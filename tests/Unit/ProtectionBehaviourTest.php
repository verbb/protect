<?php

use verbb\protect\Protect;
use verbb\protect\base\ChallengeProvider;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\FindingType;
use verbb\protect\errors\InvalidPlanException;
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
use verbb\protect\services\State;
use verbb\protect\web\assets\protect\ProtectAsset;

use craft\elements\User;
use craft\web\Request;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Twig\Markup;

class ReplacementState extends State
{
}

class SecretHoldingChallenge extends ChallengeProvider
{
    public string $token = 'private-token-value';

    public static function handle(): string { return 'secret-holding'; }

    public function secretAttributes(): array
    {
        return [...parent::secretAttributes(), 'token'];
    }

    protected function siteVerifyUrl(): string { return 'https://example.test/verify'; }
}

function akismet(array $config = []): Akismet
{
    return new Akismet($config + ['apiKey' => 'key', 'blogUrl' => 'https://example.com']);
}

function akismetContext(array $content = []): ProtectionContext
{
    return new ProtectionContext('test', content: new ProtectionContent('Text', ...$content), request: new RequestSignals('192.0.2.2', 'agent'));
}

function protectLogged(string $needle): array
{
    return array_values(array_filter(array_map(fn(array $message) => $message[0], Craft::getLogger()->messages), fn($message) => is_string($message) && str_contains($message, $needle)));
}

it('renders hidden inputs as safe markup with a hidden honeypot', function() {
    $plan = (new ProtectionPlan())->add(new Honeypot())->add(new JavaScriptPresence());
    $markup = Protect::getInstance()->prepare(new ProtectionContext('test'), $plan)->renderHiddenInputs();

    expect($markup)->toBeInstanceOf(Markup::class)
        ->and((string)$markup)->toMatch('/<div class="protect-honeypot" aria-hidden="true" hidden>/')
        ->and((string)$markup)->toContain('__protect[javascript]');
});

it('renders browser inputs with the manifest marker and registers the bundle', function() {
    $plan = (new ProtectionPlan())->add(new Honeypot())->add(new JavaScriptPresence());
    $preparation = Protect::getInstance()->prepare(new ProtectionContext('test'), $plan);
    $view = Craft::$app->getView();
    $markup = (string)$preparation->renderBrowserInputs();

    preg_match('/<div hidden data-verbb-protect="([^"]+)"><\/div>$/', $markup, $matches);

    expect($markup)->toStartWith((string)$preparation->renderHiddenInputs())
        ->and($matches)->toHaveCount(2)
        ->and(json_decode(html_entity_decode($matches[1]), true))->toBe(json_decode(json_encode($preparation->browserManifest()), true))
        ->and($view->assetBundles)->toHaveKey(ProtectAsset::class)
        ->and($view->assetBundles[ProtectAsset::class]->jsOptions)->not->toHaveKey('nonce');

    // The nonce goes on the bundle's own script tag, never into the page as readable text.
    $withNonce = (string)$preparation->renderBrowserInputs('abc123');

    expect($view->assetBundles[ProtectAsset::class]->jsOptions)->toBe(['type' => 'module', 'nonce' => 'abc123'])
        ->and($withNonce)->not->toContain('abc123');
});

it('ships the bundle that ProtectAsset publishes', function() {
    $bundle = new ProtectAsset();

    expect($bundle->js)->toBe(['protect.js'])
        ->and($bundle->jsOptions)->toBe(['type' => 'module'])
        ->and(file_get_contents($bundle->sourcePath . '/protect.js'))->toStartWith('/*! @verbb/protect ' . json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true)['version'] . ' |');
});

it('adds issuedAt, and notBefore only for a configured minimum time', function() {
    $protect = Protect::getInstance();
    $protect->getPreparations()->clock = fn() => 1000000;

    $none = $protect->prepare(new ProtectionContext('test'), (new ProtectionPlan())->add(new Honeypot()))->browserManifest()->jsonSerialize();
    $zero = $protect->prepare(new ProtectionContext('test'), (new ProtectionPlan())->add(new MinimumTime(['seconds' => 0])))->browserManifest()->jsonSerialize();
    $some = $protect->prepare(new ProtectionContext('test'), (new ProtectionPlan())->add(new MinimumTime(['seconds' => 3])))->browserManifest()->jsonSerialize();

    expect($none['version'])->toBe(1)
        ->and($none['issuedAt'])->toBe(1000000)
        ->and($none)->not->toHaveKey('notBefore')
        ->and($zero)->not->toHaveKey('notBefore')
        ->and($some['notBefore'])->toBe(1000003);
});

it('tolerates a few seconds of node clock skew and no more', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Honeypot());
    $protect->getPreparations()->clock = fn() => 1000000;
    $evidence = protectEvidence($plan);

    $protect->getPreparations()->clock = fn() => 999996;
    expect($protect->check(new ProtectionContext('test', evidence: $evidence), $plan)->checksPassed())->toBeTrue();

    $protect->getPreparations()->clock = fn() => 999990;
    $result = $protect->check(new ProtectionContext('test', evidence: $evidence), $plan);

    expect($result->checksPassed())->toBeFalse()
        ->and(iterator_to_array($result->findings())[0]->code)->toBe('preparation-expired');
});

it('reads evidence from the request body and subjects from elements', function() {
    $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['getBodyParam'])->getMock();
    $request->method('getBodyParam')->with('__protect', [])->willReturn(['preparation' => 'proof', 'javascript' => '1', 'honeypot' => ['abc' => '']]);
    $evidence = ProtectionEvidence::fromRequest($request);

    expect($evidence->preparation)->toBe('proof')
        ->and($evidence->javascript)->toBeTrue()
        ->and($evidence->honeypot)->toBe(['abc' => '']);

    $user = new User(['id' => 5, 'siteId' => 2]);
    $subject = SubjectReference::fromElement($user);

    expect($subject->type)->toBe(User::class)
        ->and($subject->id)->toBe('5')
        ->and($subject->siteId)->toBe(2)
        ->and($subject->fingerprint())->not->toBe((new SubjectReference(User::class, '6', 2))->fingerprint());
});

it('enforces minimum time at its exact boundary, with no second expiry', function() {
    $protect = Protect::getInstance();
    $protect->getPreparations()->clock = fn() => 1000000;
    $plan = (new ProtectionPlan(preparationTtl: 86400))->add(new MinimumTime(['seconds' => 3]));
    $evidence = protectEvidence($plan);
    $context = new ProtectionContext('test', evidence: $evidence);

    $protect->getPreparations()->clock = fn() => 1000002;
    expect($protect->check($context, $plan)->checksPassed())->toBeFalse();

    $protect->getPreparations()->clock = fn() => 1000003;
    expect($protect->check($context, $plan)->checksPassed())->toBeTrue();

    // `preparationTtl` is the only expiry, so a day-old form with a long TTL still passes.
    $protect->getPreparations()->clock = fn() => 1086399;
    expect($protect->check($context, $plan)->checksPassed())->toBeTrue();

    expect(fn() => (new ProtectionPlan())->add(new MinimumTime(['seconds' => 301]))->validate())->toThrow(InvalidPlanException::class);
});

it('charges no rule when a later rule is missing a dimension', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [
        new RateLimitRule(['handle' => 'ip', 'limit' => 1]),
        new RateLimitRule(['handle' => 'user', 'limit' => 5, 'dimensions' => ['identity.userId']]),
    ]]));
    $signals = new RequestSignals('192.0.2.50');

    expect($protect->check(new ProtectionContext($action, request: $signals), $plan)->hasFinding(FindingType::MissingRequiredSignal))->toBeTrue();

    // The IP rule's single allowance must still be there.
    $identified = new ProtectionContext($action, identity: new ProtectionIdentity(['userId' => 1]), request: $signals);

    expect($protect->check($identified, $plan)->checksPassed())->toBeTrue();
});

it('rejects an invalid rate dimension', function() {
    (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule(['dimensions' => ['request.cookie']])]]))->validate();
})->throws(InvalidPlanException::class);

it('keeps a state service supplied through module config', function() {
    Craft::$app->setModule(Protect::ID, ['class' => Protect::class, 'components' => ['state' => ReplacementState::class]]);
    $protect = Protect::getInstance();

    expect($protect->getState())->toBeInstanceOf(ReplacementState::class)
        ->and($protect->getChecks())->toBeInstanceOf(\verbb\protect\services\Checks::class)
        ->and(Craft::$app->getModule(Protect::ID))->toBe($protect);
});

it('boots lazily as a module with no static plugin handle, bootstrap or migrator', function() {
    $protect = Protect::getInstance();

    expect($protect)->toBeInstanceOf(Protect::class)
        ->and(Craft::$app->getModule(Protect::ID))->toBe($protect)
        ->and(property_exists(Protect::class, 'plugin'))->toBeFalse()
        ->and(method_exists(Protect::class, 'bootstrap'))->toBeFalse()
        ->and($protect->getComponents())->not->toHaveKey('migrator');
});

it('reports configuration problems as configuration errors, not outages', function() {
    $protect = Protect::getInstance();
    $cases = [
        [['secretKey' => '$PROTECT_UNSET_SECRET'], []],
        [['secretKey' => 'secret'], [new Response(200, [], '{"success":false,"error-codes":["invalid-input-secret"]}')]],
    ];

    foreach ($cases as [$config, $responses]) {
        $plan = (new ProtectionPlan())->add(new Challenge(['provider' => new Turnstile($config + [
            'client' => protectClient($responses),
            'siteKey' => 'site',
            'expectedAction' => 'submit',
            'hostnames' => ['example.com'],
        ])]));
        $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
        $result = $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, challenges: ['turnstile' => 'token'])), $plan);

        expect($result->hasFinding(FindingType::ConfigurationError))->toBeTrue()
            ->and($result->hasFinding(FindingType::ProviderUnavailable))->toBeFalse()
            ->and($result->hasErrors())->toBeTrue();
    }
});

it('keeps real outages as outages', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => new RecaptchaV3([
        'client' => protectClient([new Response(503)]),
        'siteKey' => 'site',
        'secretKey' => 'secret',
        'expectedAction' => 'submit',
        'hostnames' => ['example.com'],
    ])]));
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $result = $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, challenges: ['recaptcha-v3' => 'token'])), $plan);

    expect($result->hasFinding(FindingType::ProviderUnavailable))->toBeTrue()
        ->and($result->hasFinding(FindingType::ConfigurationError))->toBeFalse();
});

it('carries the failing component\'s errors on InvalidPlanException', function() {
    try {
        (new ProtectionPlan())->add(new Challenge(['provider' => new Turnstile(['siteKey' => 'site', 'secretKey' => 'secret', 'expectedAction' => 'submit'])]))->validate();
        $this->fail('The plan was accepted.');
    } catch (InvalidPlanException $e) {
        expect($e->errors)->toHaveKey('provider.hostnames')
            ->and($e->getMessage())->toContain('challenge')
            ->and($e->getMessage())->toContain('provider.hostnames')
            ->and($e->getMessage())->not->toContain('secret');
    }
});

it('throws on an empty resolved site key at prepare', function() {
    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => new Turnstile([
        'siteKey' => '$PROTECT_UNSET_SITE_KEY',
        'secretKey' => 'secret',
        'expectedAction' => 'submit',
        'hostnames' => ['example.com'],
    ])]));

    try {
        Protect::getInstance()->prepare(new ProtectionContext('test'), $plan);
        $this->fail('The plan was accepted.');
    } catch (InvalidPlanException $e) {
        expect($e->errors)->toHaveKey('siteKey');
    }
});

it('logs each error result once, redacted', function() {
    Craft::getLogger()->messages = [];
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Classification(['provider' => akismet(['client' => protectClient([new Response(500)])])]));
    $protect->check(new ProtectionContext('logged.action', identity: new ProtectionIdentity(['email' => 'private@example.test']), content: new ProtectionContent('Private text'), request: new RequestSignals('192.0.2.77')), $plan);

    $logged = protectLogged('returned an error');

    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('provider-unavailable')
        ->and($logged[0])->toContain('logged.action')
        ->and($logged[0])->not->toContain('private@example.test')
        ->and($logged[0])->not->toContain('192.0.2.77')
        ->and($logged[0])->not->toContain('Private text');
});

it('logs a passing result nothing, and logs feedback errors from the provider', function() {
    Craft::getLogger()->messages = [];
    Protect::getInstance()->check(new ProtectionContext('quiet'), new ProtectionPlan());

    expect(protectLogged('returned an error'))->toHaveCount(0);

    akismet(['client' => protectClient([new Response(500)])])->feedback(akismetContext(), true);

    expect(protectLogged('feedback through akismet failed with provider-unavailable'))->toHaveCount(1);
});

it('returns the longest retry-after across exceeded rules, or null', function() {
    $protect = Protect::getInstance();
    $protect->getPreparations()->clock = fn() => 1000000;
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [
        new RateLimitRule(['handle' => 'short', 'limit' => 1, 'window' => 60]),
        new RateLimitRule(['handle' => 'long', 'limit' => 1, 'window' => 3600]),
    ]]));
    $context = new ProtectionContext($action, request: new RequestSignals('192.0.2.60'));
    $first = $protect->check($context, $plan);
    $second = $protect->check($context, $plan);

    expect($first->retryAfter())->toBeNull()
        ->and($second->retryAfter())->toBe(intdiv(1000000, 3600) * 3600 + 3600 - 1000000);
});

it('defaults rate rules to per-actor rather than per-subject', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = fn(bool $includeSubject) => (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule(['limit' => 1, 'includeSubject' => $includeSubject])]]));
    $default = (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule(['limit' => 1])]]));
    $signals = new RequestSignals('192.0.2.61');

    expect($protect->check(new ProtectionContext($action, subject: new SubjectReference('a', '1', 1), request: $signals), $default)->checksPassed())->toBeTrue()
        ->and($protect->check(new ProtectionContext($action, subject: new SubjectReference('a', '2', 1), request: $signals), $default)->checksPassed())->toBeFalse()
        ->and($protect->check(new ProtectionContext($action . 'x', subject: new SubjectReference('a', '1', 1), request: $signals), $plan(true))->checksPassed())->toBeTrue()
        ->and($protect->check(new ProtectionContext($action . 'x', subject: new SubjectReference('a', '2', 1), request: $signals), $plan(true))->checksPassed())->toBeTrue();
});

it('applies /64 buckets only in the rate key, and keeps IPv4 visitors apart', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule(['limit' => 1])]]));
    $check = fn(string $ip) => $protect->check(new ProtectionContext($action, request: new RequestSignals($ip)), $plan)->checksPassed();

    expect($check('2001:db8:1:2::1'))->toBeTrue()
        ->and($check('2001:db8:1:2:ffff::9'))->toBeFalse()
        ->and($check('2001:db8:1:3::1'))->toBeTrue()
        ->and($check('::ffff:203.0.113.1'))->toBeTrue()
        ->and($check('::ffff:203.0.113.2'))->toBeTrue();

    $provider = akismet(['client' => protectClient([new Response(200, [], 'false')], $history)]);
    $provider->classify(new ProtectionContext('test', content: new ProtectionContent('Text'), request: new RequestSignals('2001:db8:1:2:3:4:5:6')));
    parse_str((string)$history[0]['request']->getBody(), $sent);

    expect($sent['user_ip'])->toBe('2001:db8:1:2:3:4:5:6');
});

it('excludes declared secret attributes from fingerprints, whatever they are called', function() {
    $provider = new SecretHoldingChallenge(['secretKey' => 'private-secret-value']);
    $data = json_encode($provider->fingerprintData());

    expect($data)->not->toContain('private-token-value')
        ->and($data)->not->toContain('private-secret-value')
        ->and($data)->toContain(SecretHoldingChallenge::class);

    $changed = new SecretHoldingChallenge(['secretKey' => 'rotated', 'token' => 'rotated']);

    expect($changed->fingerprintData())->toBe($provider->fingerprintData());
});

it('keeps plan fingerprints stable when only a client or condition changes', function() {
    $make = fn(array $extra) => (new ProtectionPlan('plan'))->add(new Honeypot($extra));

    expect($make([])->fingerprint())->toBe($make(['condition' => fn() => true])->fingerprint())
        ->and($make([])->fingerprint())->not->toBe($make(['priority' => 5])->fingerprint())
        ->and($make([])->fingerprint())->not->toBe($make(['enabled' => false])->fingerprint());
});

it('accepts an environment expression for blogUrl and parses it at send time', function() {
    putenv('PROTECT_TEST_BLOG=https://env.example.test');

    try {
        $provider = akismet(['client' => protectClient([new Response(200, [], 'false')], $history), 'blogUrl' => '$PROTECT_TEST_BLOG']);

        expect($provider->validate())->toBeTrue()
            ->and(akismet(['blogUrl' => '$PROTECT_TEST_UNSET'])->validate())->toBeTrue()
            ->and(akismet(['blogUrl' => 'http://bad url'])->validate())->toBeFalse();

        $provider->classify(akismetContext());
        parse_str((string)$history[0]['request']->getBody(), $sent);

        expect($sent['blog'])->toBe('https://env.example.test');
    } finally {
        putenv('PROTECT_TEST_BLOG');
    }

    expect(akismet(['blogUrl' => '$PROTECT_TEST_UNSET', 'client' => protectClient([])])->classify(akismetContext())->code)->toBe('provider-configuration');
});

it('sends Akismet its documented fields', function() {
    $provider = akismet(['client' => protectClient([new Response(200, [], 'false')], $history), 'blogLanguage' => 'en']);
    $created = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('Australia/Melbourne'));

    $context = (new ProtectionContext(
        'test',
        identity: new ProtectionIdentity(['email' => 'author@example.test']),
        subject: new SubjectReference('article', '1', 1, 'https://example.test/article'),
        content: new ProtectionContent('Body', authorName: 'Author', authorUrl: 'https://author.test', dateCreated: $created, edited: true),
        request: new RequestSignals('192.0.2.2', 'agent', 'https://example.test/from'),
        evidence: new ProtectionEvidence('proof', ['abc123' => '']),
    ))->withPreparation(['honeypot' => 'abc123']);

    $provider->classify($context);
    $request = $history[0]['request'];
    parse_str((string)$request->getBody(), $sent);

    expect($sent['comment_author'])->toBe('Author')
        ->and($sent['comment_author_url'])->toBe('https://author.test')
        ->and($sent['comment_author_email'])->toBe('author@example.test')
        ->and($sent['comment_date_gmt'])->toBe('2026-01-01T16:04:05+00:00')
        ->and($sent['recheck_reason'])->toBe('edit')
        ->and($sent['blog_charset'])->toBe('UTF-8')
        ->and($sent['blog_lang'])->toBe('en')
        ->and($sent['referrer'])->toBe('https://example.test/from')
        ->and($sent['permalink'])->toBe('https://example.test/article')
        ->and($sent['honeypot_field_name'])->toBe('abc123')
        ->and($sent)->toHaveKey('abc123')
        ->and($request->getHeaderLine('User-Agent'))->toMatch('#^Craft/\S+ \| VerbbProtect/1\.0$#');
});

it('omits Akismet fields it has no value for', function() {
    $provider = akismet(['client' => protectClient([new Response(200, [], 'false')], $history)]);
    $provider->classify(akismetContext());
    parse_str((string)$history[0]['request']->getBody(), $sent);

    expect($sent)->not->toHaveKeys(['comment_date_gmt', 'recheck_reason', 'honeypot_field_name', 'is_test', 'referrer']);
});

it('maps Akismet responses by status and body', function(Response $response, CheckStatus $status, string $code) {
    expect(akismet(['client' => protectClient([$response])])->classify(akismetContext()))
        ->status->toBe($status)
        ->code->toBe($code);
})->with([
    'outage 500' => fn() => [new Response(500), CheckStatus::Error, 'provider-unavailable'],
    'outage 502' => fn() => [new Response(502), CheckStatus::Error, 'provider-unavailable'],
    'throttled' => fn() => [new Response(429), CheckStatus::Error, 'provider-unavailable'],
    'other status' => fn() => [new Response(403), CheckStatus::Error, 'provider-http-error'],
    'revoked key' => fn() => [new Response(200, ['X-akismet-debug-help' => 'Invalid key'], 'invalid'), CheckStatus::Error, 'provider-configuration'],
    'verdict true' => fn() => [new Response(200, [], 'true'), CheckStatus::Failed, 'spam-detected'],
    'verdict false' => fn() => [new Response(200, [], 'false'), CheckStatus::Passed, 'classification-passed'],
    'alert keeps verdict' => fn() => [new Response(200, ['X-akismet-alert-code' => '10001', 'X-akismet-alert-msg' => 'Over limit'], 'true'), CheckStatus::Failed, 'spam-detected'],
    'alert with an error body' => fn() => [new Response(200, ['X-akismet-alert-code' => '10001'], 'invalid'), CheckStatus::Error, 'provider-configuration'],
]);

it('reports a dropped Akismet connection as unavailable', function() {
    $client = protectClient([new ConnectException('down', new PsrRequest('POST', 'https://rest.akismet.com'))]);

    expect(akismet(['client' => $client])->classify(akismetContext())->code)->toBe('provider-unavailable');
});

it('maps Akismet feedback the same way', function(Response $response, CheckStatus $status, string $code) {
    expect(akismet(['client' => protectClient([$response])])->feedback(akismetContext(), false))
        ->status->toBe($status)
        ->code->toBe($code);
})->with([
    'received' => fn() => [new Response(200, [], 'Thanks for making the web a better place.'), CheckStatus::Passed, 'feedback-received'],
    'error body' => fn() => [new Response(200, [], 'invalid'), CheckStatus::Error, 'provider-configuration'],
    'outage' => fn() => [new Response(503), CheckStatus::Error, 'provider-unavailable'],
    'other status' => fn() => [new Response(404), CheckStatus::Error, 'provider-http-error'],
]);

it('sends feedback to the matching endpoint', function() {
    akismet(['client' => protectClient([new Response(200, [], 'Thanks for making the web a better place.')], $spam)])->feedback(akismetContext(), true);
    akismet(['client' => protectClient([new Response(200, [], 'Thanks for making the web a better place.')], $ham)])->feedback(akismetContext(), false);

    expect((string)$spam[0]['request']->getUri())->toEndWith('/submit-spam')
        ->and((string)$ham[0]['request']->getUri())->toEndWith('/submit-ham');
});

it('logs Akismet debug help without control characters, and an alert once a day', function() {
    Craft::getLogger()->messages = [];
    Craft::$app->getCache()->delete('protect:akismet-alert:10042');

    akismet(['client' => protectClient([new Response(200, ['X-akismet-debug-help' => 'Empty user_ip'], 'invalid')])])->classify(akismetContext());

    expect(protectLogged('Empty user_ip'))->toHaveCount(1);

    foreach ([1, 2] as $attempt) {
        akismet(['client' => protectClient([new Response(200, ['X-akismet-alert-code' => '10042'], 'false')])])->classify(akismetContext());
    }

    expect(protectLogged('account alert (code 10042)'))->toHaveCount(1);
});

it('keeps the verdict and logs the alert when the alert throttle\'s cache fails', function() {
    Craft::getLogger()->messages = [];
    $cache = Craft::$app->getCache();
    $broken = $this->createMock(\yii\caching\CacheInterface::class);
    $broken->method('add')->willThrowException(new RuntimeException('private-backend'));
    Craft::$app->set('cache', $broken);

    try {
        $result = akismet(['client' => protectClient([new Response(200, ['X-akismet-alert-code' => '10043'], 'true')])])->classify(akismetContext());
    } finally {
        Craft::$app->set('cache', $cache);
    }

    expect($result->code)->toBe('spam-detected')
        ->and(protectLogged('account alert (code 10043)'))->toHaveCount(1);
});

it('has no local challenge timestamp window', function() {
    $body = ['success' => true, 'action' => 'submit', 'hostname' => 'example.com', 'challenge_ts' => '2001-01-01T00:00:00Z'];
    $provider = new Turnstile(['client' => protectClient([new Response(200, [], json_encode($body))]), 'siteKey' => 's', 'secretKey' => 's', 'expectedAction' => 'submit', 'hostnames' => ['example.com']]);

    expect($provider->verify('token', new ProtectionContext('test'))->status)->toBe(CheckStatus::Passed);
});

it('treats a reCAPTCHA v2 key on the v3 driver as a configuration problem', function() {
    $provider = new RecaptchaV3(['client' => protectClient([new Response(200, [], json_encode(['success' => true, 'hostname' => 'example.com', 'challenge_ts' => gmdate('c')]))]), 'siteKey' => 's', 'secretKey' => 's', 'expectedAction' => 'submit', 'hostnames' => ['example.com']]);

    expect($provider->verify('token', new ProtectionContext('test'))->code)->toBe('provider-configuration');
});

it('gives the Turnstile retry a tighter time budget', function() {
    $client = protectClient([new Response(503), new Response(503)], $requests);
    $provider = new Turnstile(['client' => $client, 'siteKey' => 's', 'secretKey' => 's', 'expectedAction' => 'submit', 'hostnames' => ['example.com']]);
    $result = $provider->verify('token', new ProtectionContext('test'));

    expect($result->code)->toBe('provider-unavailable')
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]['options']['timeout'])->toBe(5)
        ->and($requests[0]['options']['connect_timeout'])->toBe(2)
        ->and($requests[1]['options']['timeout'])->toBe(3)
        ->and($requests[1]['options']['connect_timeout'])->toBe(1)
        ->and($requests[0]['options']['allow_redirects'])->toBeFalse();
});

it('accepts built-in and namespaced identity keys only', function() {
    $identity = new ProtectionIdentity(['userId' => 5, 'email' => null, 'example.participant' => 'hash', 'my-plugin.customer' => 7]);

    expect($identity->get('userId'))->toBe(5)
        ->and($identity->get(\verbb\protect\enums\IdentitySignalType::UserId))->toBe(5)
        ->and($identity->get('email'))->toBeNull()
        ->and($identity->get('example.participant'))->toBe('hash');

    foreach (['actor', 'Example.participant', 'example.', '.participant', 'example.a.b', ''] as $key) {
        expect(fn() => new ProtectionIdentity([$key => 'x']))->toThrow(InvalidArgumentException::class);
    }

    expect(fn() => new ProtectionIdentity(['userId' => ['nested']]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ProtectionIdentity(['value']))->toThrow(InvalidArgumentException::class);
});

it('limits on a namespaced identity dimension', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())->add(new RateLimit(['rules' => [new RateLimitRule(['limit' => 1, 'dimensions' => ['identity.example.participant']])]]));
    $context = new ProtectionContext($action, identity: new ProtectionIdentity(['example.participant' => 'one']));

    expect($protect->check($context, $plan)->checksPassed())->toBeTrue()
        ->and($protect->check($context, $plan)->hasFinding(FindingType::RateLimitExceeded))->toBeTrue()
        ->and($protect->check(new ProtectionContext($action, identity: new ProtectionIdentity(['example.participant' => 'two'])), $plan)->checksPassed())->toBeTrue();
});

it('lets the same idempotency key through and refuses a different one', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new ReplayProtection());
    $evidence = protectEvidence($plan);
    $first = $protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'key-a'), $plan);
    $retry = $protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'key-a'), $plan);
    $other = $protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'key-b'), $plan);

    expect($first->checksPassed())->toBeTrue()
        ->and($first->resultFor(ReplayProtection::class)->reason)->toBeNull()
        ->and($retry->checksPassed())->toBeTrue()
        ->and($retry->resultFor(ReplayProtection::class)->reason)->toBe('idempotent-retry')
        ->and($other->hasFinding(FindingType::ReplayDetected))->toBeTrue();
});

it('treats an empty key as strict single use, and never mixes keyed and strict claims', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new ReplayProtection());

    $evidence = protectEvidence($plan);
    expect($protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: ''), $plan)->checksPassed())->toBeTrue()
        ->and($protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: ''), $plan)->hasFinding(FindingType::ReplayDetected))->toBeTrue()
        ->and($protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'key'), $plan)->hasFinding(FindingType::ReplayDetected))->toBeTrue();

    $evidence = protectEvidence($plan);
    expect($protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'key'), $plan)->checksPassed())->toBeTrue()
        ->and($protect->check(new ProtectionContext('test', evidence: $evidence), $plan)->hasFinding(FindingType::ReplayDetected))->toBeTrue();

    expect((new ProtectionContext('test', idempotencyKey: ''))->idempotencyKey)->toBeNull();
});

it('stores a hash of the idempotency key and keeps it out of the fingerprint', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new ReplayProtection());
    $fingerprint = $plan->fingerprint();
    $evidence = protectEvidence($plan);
    $cache = new \yii\caching\ArrayCache();
    $protect->set('state', new State(['cache' => $cache]));
    $result = $protect->check(new ProtectionContext('test', evidence: $evidence, idempotencyKey: 'raw-consumer-key'), $plan);
    $stored = [];

    foreach ((new ReflectionProperty($cache, '_cache'))->getValue($cache) as $entry) {
        $stored[] = unserialize($entry[0]);
    }

    expect($result->checksPassed())->toBeTrue()
        ->and($plan->fingerprint())->toBe($fingerprint)
        ->and(json_encode($stored))->not->toContain('raw-consumer-key')
        ->and($stored[0][0])->toBe(hash('sha256', 'raw-consumer-key'));
});

it('still counts an idempotent retry against rate limits', function() {
    $protect = Protect::getInstance();
    $action = 'test.' . bin2hex(random_bytes(8));
    $plan = (new ProtectionPlan())
        ->add(new ReplayProtection())
        ->add(new RateLimit(['rules' => [new RateLimitRule(['limit' => 1])]]));
    $evidence = protectEvidence($plan, $action);
    $context = new ProtectionContext($action, request: new RequestSignals('192.0.2.70'), evidence: $evidence, idempotencyKey: 'key');

    expect($protect->check($context, $plan)->checksPassed())->toBeTrue()
        ->and($protect->check($context, $plan)->hasFinding(FindingType::RateLimitExceeded))->toBeTrue();
});

it('needs a fresh token for an idempotent retry, keeping the preparation and key', function() {
    $protect = Protect::getInstance();
    $success = ['success' => true, 'action' => 'submit', 'hostname' => 'example.com', 'challenge_ts' => gmdate('c')];
    $plan = (new ProtectionPlan())
        ->add(new ReplayProtection())
        ->add(new Challenge(['provider' => new Turnstile([
            'client' => protectClient([
                new Response(200, [], json_encode($success)),
                new Response(200, [], '{"success":false,"error-codes":["timeout-or-duplicate"]}'),
                new Response(200, [], json_encode($success)),
            ]),
            'siteKey' => 'site',
            'secretKey' => 'secret',
            'expectedAction' => 'submit',
            'hostnames' => ['example.com'],
        ])]));
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $attempt = fn(string $token) => $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation, challenges: ['turnstile' => $token]), idempotencyKey: 'key'), $plan);

    expect($attempt('token-1')->checksPassed())->toBeTrue();

    $spent = $attempt('token-1');

    expect($spent->hasFinding(FindingType::ChallengeFailed))->toBeTrue()
        ->and(iterator_to_array($spent->findings())[0]->code)->toBe('challenge-rejected')
        ->and($spent->hasFinding(FindingType::ReplayDetected))->toBeFalse()
        ->and($attempt('token-2')->checksPassed())->toBeTrue();
});

it('fails a missing challenge token closed, as an adverse finding rather than an error', function() {
    $protect = Protect::getInstance();
    $plan = (new ProtectionPlan())->add(new Challenge(['provider' => new Turnstile(['client' => protectClient([]), 'siteKey' => 'site', 'secretKey' => 'secret', 'expectedAction' => 'submit', 'hostnames' => ['example.com']])]));
    $manifest = $protect->prepare(new ProtectionContext('test'), $plan)->browserManifest();
    $result = $protect->check(new ProtectionContext('test', evidence: new ProtectionEvidence($manifest->preparation)), $plan);

    expect($result->hasFinding(FindingType::ChallengeFailed))->toBeTrue()
        ->and($result->hasErrors())->toBeFalse();
});

it('keeps context metadata as an escape hatch for scalar values only', function() {
    expect((new ProtectionContext('test', metadata: ['source' => 'event']))->metadata)->toBe(['source' => 'event'])
        ->and(fn() => new ProtectionContext('test', metadata: ['nested' => []]))->toThrow(InvalidArgumentException::class);
});
