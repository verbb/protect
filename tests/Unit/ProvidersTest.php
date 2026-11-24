<?php

use verbb\protect\Protect;
use verbb\protect\base\ClassifierFeedbackInterface;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\FindingType;
use verbb\protect\integrations\challenges\FriendlyCaptcha;
use verbb\protect\integrations\challenges\Hcaptcha;
use verbb\protect\integrations\classifiers\CleanTalk;
use verbb\protect\integrations\classifiers\OopSpam;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RequestSignals;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;

// Deterministic HTTP responses for the hCaptcha, Friendly Captcha, CleanTalk and OOPSpam
// providers. No live account is used.

function hcaptchaProvider(array $config = []): Hcaptcha
{
    return new Hcaptcha($config + ['siteKey' => 'site', 'secretKey' => 'secret', 'hostnames' => ['example.com']]);
}

function friendlyProvider(array $config = []): FriendlyCaptcha
{
    return new FriendlyCaptcha($config + ['siteKey' => 'FCsite', 'secretKey' => 'api-key', 'hostnames' => ['example.com']]);
}

function cleanTalkProvider(array $config = []): CleanTalk
{
    return new CleanTalk($config + ['apiKey' => 'key']);
}

function oopSpamProvider(array $config = []): OopSpam
{
    return new OopSpam($config + ['apiKey' => 'key']);
}

function classifierContext(): ProtectionContext
{
    return new ProtectionContext(
        'example.reply',
        identity: new ProtectionIdentity(['email' => 'author@example.test']),
        content: new ProtectionContent('Neutral text', authorName: 'Author', fields: ['private' => 'never-send']),
        request: new RequestSignals('192.0.2.2', 'agent', 'https://example.test/from'),
        metadata: ['private' => 'never-send'],
    );
}

function jsonResponse(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

function providerLogged(string $needle): array
{
    return array_values(array_filter(array_map(fn(array $message) => $message[0], Craft::getLogger()->messages), fn($message) => is_string($message) && str_contains($message, $needle)));
}

function sentJson(array $history, int $index = 0): array
{
    return json_decode((string)$history[$index]['request']->getBody(), true);
}

it('registers the four providers under their handles', function() {
    $protect = Protect::getInstance();

    expect($protect->getChallenges()->getAllChallengeTypes())
        ->toMatchArray(['hcaptcha' => Hcaptcha::class, 'friendly-captcha' => FriendlyCaptcha::class])
        ->and($protect->getClassifiers()->getAllClassifierTypes())
        ->toMatchArray(['cleantalk' => CleanTalk::class, 'oopspam' => OopSpam::class]);
});

it('needs no action for hCaptcha or Friendly Captcha, but still needs hostnames', function(string $class) {
    expect((new $class(['siteKey' => 'site', 'secretKey' => 'secret', 'hostnames' => ['example.com']]))->validate())->toBeTrue();

    $provider = new $class(['siteKey' => 'site', 'secretKey' => 'secret']);

    expect($provider->validate())->toBeFalse()
        ->and($provider->getErrors())->toHaveKey('hostnames')
        ->and($provider->getErrors())->not->toHaveKey('expectedAction');
})->with([Hcaptcha::class, FriendlyCaptcha::class]);

it('prepares and checks an hCaptcha challenge through the public API', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => hcaptchaProvider([
        'client' => protectClient([jsonResponse(['success' => true, 'hostname' => 'example.com', 'challenge_ts' => gmdate('c')])], $history),
        'secretKey' => 'private-secret-value',
    ])]));
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $result = $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, challenges: ['hcaptcha' => 'token'])), $plan);
    parse_str((string)$history[0]['request']->getBody(), $sent);

    expect($manifest->challenges)->toBe([['provider' => 'hcaptcha', 'siteKey' => 'site', 'action' => '']])
        ->and(json_encode($manifest))->not->toContain('private-secret-value')
        ->and($result->checksPassed())->toBeTrue()
        ->and((string)$history[0]['request']->getUri())->toBe('https://api.hcaptcha.com/siteverify')
        ->and($sent)->toBe(['secret' => 'private-secret-value', 'response' => 'token', 'sitekey' => 'site']);
});

it('sends hCaptcha the IP address only when asked', function() {
    $context = new ProtectionContext('test', request: new RequestSignals('192.0.2.9'));
    $ok = fn() => jsonResponse(['success' => true, 'hostname' => 'example.com']);

    hcaptchaProvider(['client' => protectClient([$ok()], $default)])->verify('token', $context);
    hcaptchaProvider(['client' => protectClient([$ok()], $opted), 'sendIp' => true])->verify('token', $context);
    parse_str((string)$default[0]['request']->getBody(), $first);
    parse_str((string)$opted[0]['request']->getBody(), $second);

    expect($first)->not->toHaveKey('remoteip')
        ->and($second['remoteip'])->toBe('192.0.2.9');
});

it('maps hCaptcha responses', function(Response $response, CheckStatus $status, string $code, array $config) {
    expect(hcaptchaProvider(['client' => protectClient([$response])] + $config)->verify('token', new ProtectionContext('test')))
        ->status->toBe($status)
        ->code->toBe($code);
})->with([
    'passed' => fn() => [jsonResponse(['success' => true, 'hostname' => 'Example.com']), CheckStatus::Passed, 'challenge-passed', []],
    'another hostname' => fn() => [jsonResponse(['success' => true, 'hostname' => 'evil.test']), CheckStatus::Failed, 'challenge-context-mismatch', []],
    'hostname shed under load' => fn() => [jsonResponse(['success' => true, 'hostname' => 'not-provided']), CheckStatus::Failed, 'challenge-context-mismatch', []],
    'no hostname' => fn() => [jsonResponse(['success' => true]), CheckStatus::Error, 'invalid-provider-response', []],
    'used token' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['already-seen-response']]), CheckStatus::Failed, 'challenge-rejected', []],
    'expired token' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['expired-input-response']]), CheckStatus::Failed, 'challenge-rejected', []],
    'wrong secret' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['invalid-input-secret']]), CheckStatus::Error, 'provider-configuration', []],
    'site key on another account' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['sitekey-secret-mismatch']]), CheckStatus::Error, 'provider-configuration', []],
    'test site key with a real secret' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['not-using-dummy-passcode']]), CheckStatus::Error, 'provider-configuration', []],
    'unknown code' => fn() => [jsonResponse(['success' => false, 'error-codes' => ['something-new']]), CheckStatus::Error, 'invalid-provider-response', []],
    'outage' => fn() => [new Response(503), CheckStatus::Error, 'provider-unavailable', []],
    'other status' => fn() => [new Response(403), CheckStatus::Error, 'provider-http-error', []],
    'malformed' => fn() => [new Response(200, [], 'malformed'), CheckStatus::Error, 'invalid-provider-response', []],
    'score under the maximum' => fn() => [jsonResponse(['success' => true, 'hostname' => 'example.com', 'score' => 0.2]), CheckStatus::Passed, 'challenge-passed', ['maximumScore' => 0.5]],
    'score over the maximum' => fn() => [jsonResponse(['success' => true, 'hostname' => 'example.com', 'score' => 0.9]), CheckStatus::Failed, 'challenge-score', ['maximumScore' => 0.5]],
    'maximum without Enterprise' => fn() => [jsonResponse(['success' => true, 'hostname' => 'example.com']), CheckStatus::Error, 'provider-configuration', ['maximumScore' => 0.5]],
    'malformed score' => fn() => [jsonResponse(['success' => true, 'hostname' => 'example.com', 'score' => '0.2']), CheckStatus::Error, 'invalid-provider-response', ['maximumScore' => 0.5]],
    'score ignored without a maximum' => fn() => [jsonResponse(['success' => true, 'hostname' => 'example.com', 'score' => 0.99]), CheckStatus::Passed, 'challenge-passed', []],
]);

it('keeps the hCaptcha score and threshold on the finding', function() {
    $result = hcaptchaProvider(['client' => protectClient([jsonResponse(['success' => true, 'hostname' => 'example.com', 'score' => 0.9])]), 'maximumScore' => 0.5])->verify('token', new ProtectionContext('test'));

    expect($result->metadata)->toBe(['score' => 0.9, 'threshold' => 0.5])
        ->and(hcaptchaProvider(['maximumScore' => 1.5])->validate())->toBeFalse();
});

it('prepares and checks a Friendly Captcha challenge through the public API', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => friendlyProvider([
        'client' => protectClient([jsonResponse(['success' => true, 'data' => ['event_id' => 'e', 'challenge' => ['timestamp' => gmdate('c'), 'origin' => 'https://example.com'], 'risk_intelligence' => null]])], $history),
        'secretKey' => 'private-api-key',
        'sendIp' => true,
    ])]));
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $result = $protect->check(new ProtectionContext('test', request: new RequestSignals('192.0.2.9'), evidence: new ProtectionEvidence($manifest->preparation, challenges: ['friendly-captcha' => 'solution'])), $plan);
    $request = $history[0]['request'];

    expect($manifest->challenges)->toBe([['provider' => 'friendly-captcha', 'siteKey' => 'FCsite', 'action' => '', 'endpoint' => 'global']])
        ->and(json_encode($manifest))->not->toContain('private-api-key')
        ->and($result->checksPassed())->toBeTrue()
        ->and((string)$request->getUri())->toBe('https://global.frcapi.com/api/v2/captcha/siteverify')
        ->and($request->getHeaderLine('X-API-Key'))->toBe('private-api-key')
        ->and($request->getHeaderLine('Content-Type'))->toContain('application/json')
        ->and(sentJson($history))->toBe(['response' => 'solution', 'sitekey' => 'FCsite']);
});

it('uses Friendly Captcha\'s EU endpoint in the browser and on the server', function() {
    $provider = friendlyProvider(['client' => protectClient([jsonResponse(['success' => true, 'data' => ['challenge' => ['origin' => 'https://example.com']]])], $history), 'endpoint' => 'eu']);

    expect($provider->prepare(new ProtectionContext('test'))['endpoint'])->toBe('eu')
        ->and($provider->verify('solution', new ProtectionContext('test'))->status)->toBe(CheckStatus::Passed)
        ->and((string)$history[0]['request']->getUri())->toBe('https://eu.frcapi.com/api/v2/captcha/siteverify')
        ->and(friendlyProvider(['endpoint' => 'us'])->validate())->toBeFalse();
});

it('maps Friendly Captcha responses', function(Response $response, CheckStatus $status, string $code) {
    expect(friendlyProvider(['client' => protectClient([$response])])->verify('solution', new ProtectionContext('test')))
        ->status->toBe($status)
        ->code->toBe($code);
})->with([
    'passed' => fn() => [jsonResponse(['success' => true, 'data' => ['challenge' => ['origin' => 'https://example.com']]]), CheckStatus::Passed, 'challenge-passed'],
    'origin with a port and different case' => fn() => [jsonResponse(['success' => true, 'data' => ['challenge' => ['origin' => 'https://EXAMPLE.com:8443']]]), CheckStatus::Passed, 'challenge-passed'],
    'another origin' => fn() => [jsonResponse(['success' => true, 'data' => ['challenge' => ['origin' => 'https://evil.test']]]), CheckStatus::Failed, 'challenge-context-mismatch'],
    'unknown origin' => fn() => [jsonResponse(['success' => true, 'data' => ['challenge' => ['origin' => '']]]), CheckStatus::Failed, 'challenge-context-mismatch'],
    'no origin' => fn() => [jsonResponse(['success' => true, 'data' => []]), CheckStatus::Error, 'invalid-provider-response'],
    'invalid response' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'response_invalid', 'detail' => 'x']]), CheckStatus::Failed, 'challenge-rejected'],
    'expired response' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'response_timeout', 'detail' => 'x']]), CheckStatus::Failed, 'challenge-rejected'],
    'used response' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'response_duplicate', 'detail' => 'x']]), CheckStatus::Failed, 'challenge-rejected'],
    'unknown code' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'something_new', 'detail' => 'x']]), CheckStatus::Error, 'invalid-provider-response'],
    'missing API key' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'auth_required', 'detail' => 'x']], 401), CheckStatus::Error, 'provider-configuration'],
    'wrong API key' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'auth_invalid', 'detail' => 'x']], 401), CheckStatus::Error, 'provider-configuration'],
    'wrong site key' => fn() => [jsonResponse(['success' => false, 'error' => ['error_code' => 'sitekey_invalid', 'detail' => 'x']], 400), CheckStatus::Error, 'provider-configuration'],
    'outage' => fn() => [new Response(502), CheckStatus::Error, 'provider-unavailable'],
    'other status' => fn() => [new Response(404), CheckStatus::Error, 'provider-http-error'],
    'malformed' => fn() => [new Response(200, [], 'malformed'), CheckStatus::Error, 'invalid-provider-response'],
]);

it('reports a missing Friendly Captcha API key without calling the service', function() {
    expect(friendlyProvider(['client' => protectClient([]), 'secretKey' => '$PROTECT_UNSET_FRIENDLY'])->verify('solution', new ProtectionContext('test'))->code)->toBe('provider-configuration')
        ->and(friendlyProvider(['client' => protectClient([])])->verify('', new ProtectionContext('test'))->code)->toBe('challenge-token-missing');
});

it('sends CleanTalk its documented fields and nothing else', function() {
    Protect::getInstance()->getPreparations()->clock = fn() => 1000;
    $context = new ProtectionContext(
        'example.reply',
        identity: new ProtectionIdentity(['email' => 'author@example.test']),
        content: new ProtectionContent('Neutral text', authorName: 'Author', fields: ['private' => 'never-send']),
        request: new RequestSignals('192.0.2.2', 'agent', 'https://example.test/from'),
        evidence: new ProtectionEvidence('proof', javascript: true),
        metadata: ['private' => 'never-send'],
    );

    cleanTalkProvider(['client' => protectClient([jsonResponse(['allow' => 1, 'codes' => 'ALLOWED'])], $history)])->classify($context->withPreparation(['issuedAt' => 988]));
    $request = $history[0]['request'];
    $sent = sentJson($history);

    expect((string)$request->getUri())->toBe('https://moderate.cleantalk.org/api2.0')
        ->and((string)$request->getBody())->not->toContain('never-send')
        ->and($sent)->toBe([
            'method_name' => 'check_message',
            'auth_key' => 'key',
            'agent' => 'verbb-protect-1.0',
            'message' => 'Neutral text',
            'sender_nickname' => 'Author',
            'sender_email' => 'author@example.test',
            'sender_ip' => '192.0.2.2',
            'sender_info' => json_encode(['REFFERRER' => 'https://example.test/from', 'USER_AGENT' => 'agent']),
            'submit_time' => 12,
            'js_on' => 1,
        ]);
});

it('omits CleanTalk fields it has no value for', function() {
    cleanTalkProvider(['client' => protectClient([jsonResponse(['allow' => 1])], $history)])->classify(new ProtectionContext('test', content: new ProtectionContent('Text')));

    expect(sentJson($history))->toBe(['method_name' => 'check_message', 'auth_key' => 'key', 'agent' => 'verbb-protect-1.0', 'message' => 'Text']);
});

it('maps CleanTalk responses', function(Response $response, CheckStatus $status, string $code) {
    expect(cleanTalkProvider(['client' => protectClient([$response])])->classify(classifierContext()))
        ->status->toBe($status)
        ->code->toBe($code);
})->with([
    'allowed' => fn() => [jsonResponse(['allow' => 1, 'codes' => 'ALLOWED', 'account_status' => 1, 'id' => 'abc']), CheckStatus::Passed, 'classification-passed'],
    'denied' => fn() => [jsonResponse(['allow' => 0, 'codes' => 'DENIED SEEMS_SPAM_MESSAGE', 'account_status' => 1, 'id' => 'abc']), CheckStatus::Failed, 'spam-detected'],
    'unknown key' => fn() => [jsonResponse(['allow' => 0, 'codes' => 'KEY_NOT_FOUND']), CheckStatus::Error, 'provider-configuration'],
    'trial expired' => fn() => [jsonResponse(['allow' => 0, 'codes' => 'TRIAL_EXPIRED']), CheckStatus::Error, 'provider-configuration'],
    'disabled account' => fn() => [jsonResponse(['allow' => 0, 'account_status' => 0]), CheckStatus::Error, 'provider-configuration'],
    'error number' => fn() => [jsonResponse(['errno' => 1, 'errstr' => 'Access key unset.']), CheckStatus::Error, 'provider-configuration'],
    'no verdict' => fn() => [jsonResponse(['codes' => 'ALLOWED']), CheckStatus::Error, 'invalid-provider-response'],
    'malformed' => fn() => [new Response(200, [], 'malformed'), CheckStatus::Error, 'invalid-provider-response'],
    'outage' => fn() => [new Response(500), CheckStatus::Error, 'provider-unavailable'],
    'throttled' => fn() => [new Response(429), CheckStatus::Error, 'provider-unavailable'],
    'other status' => fn() => [new Response(403), CheckStatus::Error, 'provider-http-error'],
]);

it('logs CleanTalk\'s explanation without control characters', function() {
    Craft::getLogger()->messages = [];
    cleanTalkProvider(['client' => protectClient([jsonResponse(['errno' => 1, 'errstr' => "*** Access key\nunset ***"])])])->classify(classifierContext());

    expect(providerLogged('CleanTalk returned an error response. *** Access key unset ***'))->toHaveCount(1);
});

it('reports a dropped CleanTalk connection or a missing key without a verdict', function() {
    $client = protectClient([new ConnectException('down', new PsrRequest('POST', 'https://moderate.cleantalk.org'))]);

    expect(cleanTalkProvider(['client' => $client])->classify(classifierContext())->code)->toBe('provider-unavailable')
        ->and(cleanTalkProvider(['client' => protectClient([]), 'apiKey' => '$PROTECT_UNSET_CLEANTALK'])->classify(classifierContext())->code)->toBe('provider-configuration')
        ->and(cleanTalkProvider())->not->toBeInstanceOf(ClassifierFeedbackInterface::class)
        ->and(json_encode(cleanTalkProvider(['apiKey' => 'private-key'])->fingerprintData()))->not->toContain('private-key');
});

it('classifies through CleanTalk in a plan', function() {
    $plan = (new ProtectionPlan())->add(new Classification(['provider' => cleanTalkProvider(['client' => protectClient([jsonResponse(['allow' => 0, 'codes' => 'DENIED'])])])]));
    $result = Protect::getInstance()->check(classifierContext(), $plan);

    expect($result->hasFinding(FindingType::SpamDetected))->toBeTrue()
        ->and($result->findings()->forProvider(CleanTalk::class))->toHaveCount(1);
});

it('sends OOPSpam its documented fields and nothing else', function() {
    oopSpamProvider(['client' => protectClient([jsonResponse(['Score' => 0])], $history), 'allowedLanguages' => ['EN'], 'blockTempEmail' => true])->classify(classifierContext());
    $request = $history[0]['request'];

    expect((string)$request->getUri())->toBe('https://api.oopspam.com/v1/spamdetection')
        ->and($request->getHeaderLine('X-Api-Key'))->toBe('key')
        ->and((string)$request->getBody())->not->toContain('never-send')
        ->and(sentJson($history))->toBe([
            'content' => 'Neutral text',
            'senderIP' => '192.0.2.2',
            'email' => 'author@example.test',
            'checkForLength' => false,
            'blockTempEmail' => true,
            'blockVPN' => false,
            'blockDC' => false,
            'urlFriendly' => false,
            'allowedLanguages' => ['en'],
            'logIt' => false,
        ]);
});

it('names the OOPSpam source only when logging', function() {
    oopSpamProvider(['client' => protectClient([jsonResponse(['Score' => 0])], $history), 'logIt' => true, 'source' => 'example.com'])->classify(classifierContext());

    expect(sentJson($history))->toMatchArray(['logIt' => true, 'source' => 'example.com'])
        ->and(oopSpamProvider(['logIt' => true])->validate())->toBeFalse()
        ->and(oopSpamProvider(['source' => 'example.com'])->validate())->toBeTrue()
        ->and(oopSpamProvider(['spamThreshold' => 0])->validate())->toBeFalse()
        ->and(oopSpamProvider(['blockedCountries' => ['usa']])->validate())->toBeFalse();
});

it('maps OOPSpam scores and statuses', function(Response $response, CheckStatus $status, string $code, array $metadata) {
    $result = oopSpamProvider(['client' => protectClient([$response])])->classify(classifierContext());

    expect($result->status)->toBe($status)
        ->and($result->code)->toBe($code)
        ->and($result->metadata)->toBe($metadata);
})->with([
    'ham' => fn() => [jsonResponse(['Score' => 2, 'Details' => ['isContentSpam' => 'nospam']]), CheckStatus::Passed, 'classification-passed', []],
    'spam at the threshold' => fn() => [jsonResponse(['Score' => 3, 'Details' => ['isContentSpam' => 'spam']]), CheckStatus::Failed, 'spam-detected', ['score' => 3, 'threshold' => 3]],
    'out of range' => fn() => [jsonResponse(['Score' => 9]), CheckStatus::Error, 'invalid-provider-response', []],
    'no score' => fn() => [jsonResponse(['Details' => []]), CheckStatus::Error, 'invalid-provider-response', []],
    'wrong key' => fn() => [jsonResponse(['message' => 'Unauthorized'], 401), CheckStatus::Error, 'provider-configuration', []],
    'rejected setting' => fn() => [jsonResponse(['message' => 'Bad request'], 400), CheckStatus::Error, 'provider-configuration', []],
    'quota or throttling' => fn() => [new Response(429), CheckStatus::Error, 'provider-unavailable', []],
    'outage' => fn() => [new Response(500), CheckStatus::Error, 'provider-unavailable', []],
    'other status' => fn() => [new Response(404), CheckStatus::Error, 'provider-http-error', []],
    'malformed' => fn() => [new Response(200, [], 'malformed'), CheckStatus::Error, 'invalid-provider-response', []],
]);

it('applies a custom OOPSpam threshold', function() {
    expect(oopSpamProvider(['client' => protectClient([jsonResponse(['Score' => 4])]), 'spamThreshold' => 5])->classify(classifierContext())->status)->toBe(CheckStatus::Passed);
});

it('requires an IP address for an OOPSpam country rule', function() {
    $plan = (new ProtectionPlan())->add(new Classification(['provider' => oopSpamProvider(['client' => protectClient([]), 'allowedCountries' => ['au']])]));
    $result = Protect::getInstance()->check(new ProtectionContext('test', content: new ProtectionContent('Text')), $plan);

    expect(oopSpamProvider()->requiredSignals())->toBe([])
        ->and($result->hasFinding(FindingType::MissingRequiredSignal))->toBeTrue();
});

it('reports OOPSpam corrections to its report endpoint', function(bool $spam) {
    $result = oopSpamProvider(['client' => protectClient([jsonResponse(['message' => 'success'])], $history)])->feedback(classifierContext(), $spam);

    expect($result->status)->toBe(CheckStatus::Passed)
        ->and($result->code)->toBe('feedback-received')
        ->and((string)$history[0]['request']->getUri())->toBe('https://api.oopspam.com/v1/spamdetection/report')
        ->and(sentJson($history)['shouldBeSpam'])->toBe($spam)
        ->and(sentJson($history)['content'])->toBe('Neutral text');
})->with([true, false]);

it('logs a failed OOPSpam correction', function() {
    Craft::getLogger()->messages = [];
    $result = oopSpamProvider(['client' => protectClient([new Response(401)])])->feedback(classifierContext(), true);
    $unexpected = oopSpamProvider(['client' => protectClient([jsonResponse(['message' => 'nope'])])])->feedback(classifierContext(), false);

    expect($result->code)->toBe('provider-configuration')
        ->and($unexpected->code)->toBe('invalid-provider-response')
        ->and(providerLogged('feedback through oopspam failed with provider-configuration'))->toHaveCount(1)
        ->and(json_encode(oopSpamProvider(['apiKey' => 'private-key'])->fingerprintData()))->not->toContain('private-key');
});
