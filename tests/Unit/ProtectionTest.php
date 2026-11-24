<?php

use verbb\protect\Protect;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\enums\FindingType;
use verbb\protect\errors\InvalidPlanException;
use verbb\protect\errors\PreparationException;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\integrations\challenges\RecaptchaV3;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

use craft\helpers\FileHelper;

use GuzzleHttp\Psr7\Response;

function turnstile(array $config = []): Turnstile
{
    return new Turnstile($config + ['siteKey' => 'site', 'secretKey' => 'secret', 'expectedAction' => 'submit', 'hostnames' => ['example.com']]);
}

function recaptcha(array $config = []): RecaptchaV3
{
    return new RecaptchaV3($config + ['siteKey' => 'site', 'secretKey' => 'secret', 'expectedAction' => 'submit', 'hostnames' => ['example.com'], 'minimumScore' => 0.5]);
}

it('binds preparation to action, subject and plan and rejects tampering', function() {
    $plan = (new ProtectionPlan())->add(new Honeypot());
    $subject = new SubjectReference('vote', '42', 1);
    $evidence = protectEvidence($plan, 'vote.cast', $subject);
    $protect = Protect::getInstance();

    expect($protect->check(new ProtectionContext('vote.cast', subject: $subject, evidence: $evidence), $plan)->checksPassed())->toBeTrue();

    foreach ([
        new ProtectionContext('other', subject: $subject, evidence: $evidence),
        new ProtectionContext('vote.cast', subject: new SubjectReference('vote', '43', 1), evidence: $evidence),
        new ProtectionContext('vote.cast', subject: $subject, evidence: new ProtectionEvidence($evidence->preparation . 'x')),
    ] as $context) {
        expect($protect->check($context, $plan)->hasAdverseFindings())->toBeTrue();
    }

    $otherPlan = (new ProtectionPlan(handle: 'changed'))->add(new Honeypot());

    expect($protect->check(new ProtectionContext('vote.cast', subject: $subject, evidence: $evidence), $otherPlan)->checksPassed())->toBeFalse();
});

it('uses signed time and a random field for passive checks', function() {
    $protect = Protect::getInstance();
    $now = time();
    $protect->getPreparations()->clock = fn() => $now;
    $plan = (new ProtectionPlan(continuation: ContinuationStrategy::RunAll))
        ->add(new Honeypot())
        ->add(new MinimumTime(['seconds' => 3]))
        ->add(new JavaScriptPresence());
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $result = $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, [$manifest->honeypot => 'bot'])), $plan);

    expect($result->findings())->toHaveCount(3);

    $protect->getPreparations()->clock = fn() => $now + 4;

    expect($protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, [], true)), $plan)->checksPassed())->toBeTrue();

    $protect->getPreparations()->clock = fn() => $now + 3601;

    expect($protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, [], true)), $plan)->checksPassed())->toBeFalse();
});

it('treats replay as single use, including after failed later checks', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new ReplayProtection());
    $context = new ProtectionContext('test', evidence: protectEvidence($plan));

    expect($protect->check($context, $plan)->checksPassed())->toBeTrue()
        ->and($protect->check($context, $plan)->hasFinding(FindingType::ReplayDetected))->toBeTrue();
});

it('scopes rate rules atomically and reports retryAfter without identities', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [
        new RateLimitRule(['limit' => 1, 'window' => 60]),
        new RateLimitRule(['handle' => 'burst', 'limit' => 2, 'window' => 300]),
    ]]));
    $context = new ProtectionContext($action, request: new RequestSignals('192.0.2.44'));

    expect($protect->check($context, $plan)->checksPassed())->toBeTrue();

    $result = $protect->check($context, $plan);

    expect($result->hasFinding(FindingType::RateLimitExceeded))->toBeTrue()
        ->and(json_encode($result))->not->toContain('192.0.2.44')
        ->and(iterator_to_array($result->findings())[0]->metadata['retryAfter'])->toBeGreaterThan(0)
        ->and($protect->check(new ProtectionContext($action, request: new RequestSignals('192.0.2.45')), $plan)->checksPassed())->toBeTrue()
        ->and($protect->check(new ProtectionContext($action), $plan)->hasFinding(FindingType::MissingRequiredSignal))->toBeTrue();
});

it('skips disabled and inapplicable checks and rejects invalid configuration', function() {
    $plan = (new ProtectionPlan())
        ->add(new Challenge(['provider' => new Turnstile(), 'enabled' => false]))
        ->add(new Classification(['provider' => new Akismet(['apiKey' => 'key', 'blogUrl' => 'https://example.com'])]));
    $result = Protect::getInstance()->check(new ProtectionContext('example.vote.cast'), $plan);

    expect($result->checksPassed())->toBeTrue()
        ->and($result->resultFor(Challenge::class))->toBeNull()
        ->and($result->resultFor(Classification::class)->status)->toBe(CheckStatus::Skipped);

    (new ProtectionPlan())->add(new Challenge(['provider' => new Turnstile()]))->validate();
})->throws(InvalidPlanException::class);

it('never exposes credentials in fingerprints, manifests or results', function() {
    $provider = turnstile(['secretKey' => '$PRIVATE_SECRET']);

    expect(json_encode($provider->fingerprintData()))->not->toContain('PRIVATE_SECRET');

    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => $provider]));
    $preparation = Protect::getInstance()->prepare(new ProtectionContext('test'), $plan);

    expect(json_encode($preparation))->not->toContain('PRIVATE_SECRET')
        ->and((string)$preparation->renderHiddenInputs())->toContain('__protect[preparation]');
});

it('distinguishes Turnstile integrity failures from provider errors', function() {
    $base = ['success' => true, 'action' => 'submit', 'hostname' => 'example.com', 'challenge_ts' => gmdate('c')];
    $cases = [
        [$base, CheckStatus::Passed],
        [array_replace($base, ['action' => 'other']), CheckStatus::Failed],
        [array_replace($base, ['hostname' => 'evil.test']), CheckStatus::Failed],
        [['success' => false, 'error-codes' => ['timeout-or-duplicate']], CheckStatus::Failed],
        [['success' => false, 'error-codes' => ['invalid-input-secret']], CheckStatus::Error],
        [['success' => true], CheckStatus::Error],
    ];

    foreach ($cases as [$body, $status]) {
        $provider = turnstile(['client' => protectClient([new Response(200, [], json_encode($body))])]);

        expect($provider->verify('token', new ProtectionContext('test'))->status)->toBe($status);
    }

    $provider = turnstile(['client' => protectClient([new Response(200, [], 'malformed')])]);

    expect($provider->verify('token', new ProtectionContext('test'))->status)->toBe(CheckStatus::Error);
});

it('retries Turnstile with one idempotency key and never discloses the IP by default', function() {
    $client = protectClient([
        new Response(200, [], '{"success":false,"error-codes":["internal-error"]}'),
        new Response(200, [], json_encode(['success' => true, 'action' => 'submit', 'hostname' => 'example.com', 'challenge_ts' => gmdate('c')])),
    ], $requests);
    $provider = turnstile(['client' => $client]);

    expect($provider->verify('token', new ProtectionContext('test', request: new RequestSignals('192.0.2.1')))->status)->toBe(CheckStatus::Passed)
        ->and($requests)->toHaveCount(2);

    parse_str((string)$requests[0]['request']->getBody(), $first);
    parse_str((string)$requests[1]['request']->getBody(), $second);

    expect($first['idempotency_key'])->toBe($second['idempotency_key'])
        ->and($first)->not->toHaveKey('remoteip');
});

it('requires reCAPTCHA integrity fields and a score', function() {
    $base = ['success' => true, 'action' => 'submit', 'hostname' => 'example.com', 'challenge_ts' => gmdate('c'), 'score' => 0.9];

    foreach ([
        [$base, CheckStatus::Passed],
        [array_replace($base, ['score' => 0.1]), CheckStatus::Failed],
        [array_replace($base, ['score' => '0.9']), CheckStatus::Error],
        [array_replace($base, ['success' => false, 'error-codes' => ['invalid-input-response']]), CheckStatus::Failed],
    ] as [$data, $status]) {
        $provider = recaptcha(['client' => protectClient([new Response(200, [], json_encode($data))])]);

        expect($provider->verify('token', new ProtectionContext('test'))->status)->toBe($status);
    }
});

it('minimises Akismet signals and distinguishes unexpected responses', function() {
    $context = new ProtectionContext(
        'example.reply',
        identity: new ProtectionIdentity(['email' => 'author@example.test']),
        content: new ProtectionContent('Neutral text', authorName: 'Author', fields: ['private' => 'never-send']),
        request: new RequestSignals('192.0.2.2', 'agent'),
        metadata: ['private' => 'never-send'],
    );

    foreach ([['true', CheckStatus::Failed], ['false', CheckStatus::Passed], ['unexpected', CheckStatus::Error]] as [$body, $status]) {
        $provider = new Akismet(['client' => protectClient([new Response(200, [], $body)], $history), 'apiKey' => 'key', 'blogUrl' => 'https://example.com', 'testMode' => true]);

        expect($provider->classify($context)->status)->toBe($status)
            ->and((string)$history[0]['request']->getBody())->not->toContain('never-send')
            ->and($history[0]['request']->getUri()->getHost())->toBe('rest.akismet.com');
    }
});

it('rejects nested tokens and unknown evidence versions', function() {
    ProtectionEvidence::fromArray(['version' => 2]);
})->throws(PreparationException::class);

it('has no lost writes across concurrent claims and counter updates', function() {
    $path = sys_get_temp_dir() . '/protect-race-' . bin2hex(random_bytes(8));
    mkdir($path);
    $workers = [];

    for ($i = 0; $i < 8; $i++) {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/state-worker.php', CRAFT_VENDOR_PATH . '/autoload.php', $path, 'concurrent'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        expect($process)->toBeResource();

        $workers[] = [$process, $pipes];
    }

    $claims = 0;
    $counts = [];

    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0, $error);

        $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $claims += (int)$data['claimed'];
        array_push($counts, ...$data['counts']);
    }

    sort($counts);

    expect($claims)->toBe(1)
        ->and($counts)->toBe(range(1, 64));

    FileHelper::removeDirectory($path);
});

it('keeps ordered diagnostics under every continuation strategy and condition', function() {
    foreach (ContinuationStrategy::cases() as $strategy) {
        $plan = (new ProtectionPlan(continuation: $strategy))->add(new Honeypot())->add(new JavaScriptPresence());
        $manifest = Protect::getInstance()->prepare(new ProtectionContext('test'), $plan)->browserManifest();
        $result = Protect::getInstance()->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, [$manifest->honeypot => '0'])), $plan);

        expect($result->results)->toHaveCount($strategy === ContinuationStrategy::RunAll ? 2 : 1)
            ->and($result->diagnostics[0]['check'])->toBe('honeypot');
    }

    $plan = (new ProtectionPlan())->add(new Honeypot(['condition' => fn() => false]));

    expect(Protect::getInstance()->check(new ProtectionContext('test'), $plan)->resultFor(Honeypot::class)->status)->toBe(CheckStatus::Skipped);
});

it('cannot pass with malformed evidence or a missing classifier signal', function() {
    foreach ([['challenges' => ['turnstile' => ['nested']]], ['honeypot' => 'string'], ['preparation' => []], ['version' => '1junk']] as $data) {
        expect(fn() => ProtectionEvidence::fromArray($data))->toThrow(PreparationException::class);
    }

    $plan = (new ProtectionPlan())->add(new Classification(['provider' => new Akismet(['apiKey' => 'key', 'blogUrl' => 'https://example.com'])]));
    $result = Protect::getInstance()->check(new ProtectionContext('test', content: new ProtectionContent('Text')), $plan);

    expect($result->hasFinding(FindingType::MissingRequiredSignal))->toBeTrue()
        ->and($result->checksPassed())->toBeFalse();
});
