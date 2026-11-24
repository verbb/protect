<?php

use verbb\protect\Protect;
use verbb\protect\base\ProviderSettingsTrait;
use verbb\protect\integrations\challenges\FriendlyCaptcha;
use verbb\protect\integrations\challenges\Hcaptcha;
use verbb\protect\integrations\challenges\RecaptchaV3;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\integrations\classifiers\CleanTalk;
use verbb\protect\integrations\classifiers\OopSpam;

use craft\base\Model;

// A plugin settings model using the shared provider settings, with protection switched by `enabled`.
class ProviderSettingsFixture extends Model
{
    use ProviderSettingsTrait;

    public bool $enabled = true;

    protected function defineRules(): array
    {
        return $this->defineProviderRules(fn() => $this->enabled);
    }
}

it('builds the chosen challenge provider with its own keys', function(string $handle, string $class, array $keys): void {
    $settings = new ProviderSettingsFixture(['challengeProvider' => $handle, ...$keys, 'challengeSendIp' => true]);
    $provider = $settings->createChallengeProvider('vote', ['example.com']);

    expect($provider)->toBeInstanceOf($class)
        ->and($provider->siteKey)->toBe('site')
        ->and($provider->secretKey)->toBe('secret')
        ->and($provider->hostnames)->toBe(['example.com'])
        ->and($provider->sendIp)->toBeTrue()
        ->and($settings->hasChallengeProvider())->toBeTrue();
})->with([
    'turnstile' => ['turnstile', Turnstile::class, ['turnstileKey' => 'site', 'turnstileSecret' => 'secret']],
    'recaptcha' => ['recaptcha', RecaptchaV3::class, ['recaptchaKey' => 'site', 'recaptchaSecret' => 'secret']],
    'hcaptcha' => ['hcaptcha', Hcaptcha::class, ['hcaptchaKey' => 'site', 'hcaptchaSecret' => 'secret']],
    'friendly captcha' => ['friendly-captcha', FriendlyCaptcha::class, ['friendlyCaptchaKey' => 'site', 'friendlyCaptchaSecret' => 'secret']],
]);

it('passes the action, score and region to the providers that use them', function(): void {
    $recaptcha = (new ProviderSettingsFixture(['challengeProvider' => 'recaptcha', 'recaptchaMinScore' => 0.7]))->createChallengeProvider('vote');
    $friendly = (new ProviderSettingsFixture(['challengeProvider' => 'friendly-captcha', 'friendlyCaptchaEndpoint' => 'eu']))->createChallengeProvider();

    expect($recaptcha->expectedAction)->toBe('vote')
        ->and($recaptcha->minimumScore)->toBe(0.7)
        ->and($friendly->endpoint)->toBe('eu');
});

it('lets keys passed in replace the saved ones', function(): void {
    $settings = new ProviderSettingsFixture(['challengeProvider' => 'turnstile', 'turnstileKey' => 'site', 'turnstileSecret' => 'secret']);
    $provider = $settings->createChallengeProvider(keys: ['siteKey' => 'other-site', 'secretKey' => 'other-secret']);

    expect($provider->siteKey)->toBe('other-site')
        ->and($provider->secretKey)->toBe('other-secret');
});

it('stands reCAPTCHA in when no challenge provider is chosen', function(): void {
    $settings = new ProviderSettingsFixture();

    expect($settings->hasChallengeProvider())->toBeFalse()
        ->and($settings->createChallengeProvider())->toBeInstanceOf(RecaptchaV3::class);
});

it('builds the chosen spam classifier', function(): void {
    $akismet = (new ProviderSettingsFixture(['spamClassifier' => 'akismet', 'akismetApiKey' => 'key', 'akismetTestMode' => true]))->createSpamClassifier('https://example.com', 'forum-post');
    $ownUrl = (new ProviderSettingsFixture(['spamClassifier' => 'akismet', 'akismetBlogUrl' => 'https://blog.example.com']))->createSpamClassifier('https://example.com');
    $cleantalk = (new ProviderSettingsFixture(['spamClassifier' => 'cleantalk', 'cleantalkApiKey' => 'key']))->createSpamClassifier('https://example.com');
    $oopspam = (new ProviderSettingsFixture(['spamClassifier' => 'oopspam', 'oopspamApiKey' => 'key', 'oopspamSpamThreshold' => 5]))->createSpamClassifier('https://example.com');

    expect($akismet)->toBeInstanceOf(Akismet::class)
        ->and($akismet->blogUrl)->toBe('https://example.com')
        ->and($akismet->contentType)->toBe('forum-post')
        ->and($akismet->testMode)->toBeTrue()
        ->and($ownUrl->blogUrl)->toBe('https://blog.example.com')
        ->and($cleantalk)->toBeInstanceOf(CleanTalk::class)
        ->and($oopspam)->toBeInstanceOf(OopSpam::class)
        ->and($oopspam->spamThreshold)->toBe(5)
        ->and((new ProviderSettingsFixture())->createSpamClassifier('https://example.com'))->toBeNull()
        ->and((new ProviderSettingsFixture())->hasSpamClassifier())->toBeFalse();
});

it('requires the chosen provider’s keys only while protection is on', function(): void {
    $settings = new ProviderSettingsFixture(['challengeProvider' => 'turnstile', 'spamClassifier' => 'akismet']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors())->toHaveKeys(['turnstileKey', 'turnstileSecret', 'akismetApiKey'])
        ->and($settings->hasErrors('recaptchaKey'))->toBeFalse();

    $settings->enabled = false;

    expect($settings->validate())->toBeTrue();
});

it('refuses unknown providers and out-of-range values', function(): void {
    $settings = new ProviderSettingsFixture([
        'challengeProvider' => 'unknown',
        'spamClassifier' => 'unknown',
        'recaptchaMinScore' => 2,
        'oopspamSpamThreshold' => 9,
        'friendlyCaptchaEndpoint' => 'mars',
        'akismetBlogUrl' => 'not a url',
    ]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors())->toHaveKeys(['challengeProvider', 'spamClassifier', 'recaptchaMinScore', 'oopspamSpamThreshold', 'friendlyCaptchaEndpoint', 'akismetBlogUrl']);
});

it('renders the settings fields for each section', function(): void {
    $settings = new ProviderSettingsFixture(['challengeProvider' => 'hcaptcha', 'hcaptchaSecret' => 'plain-secret']);
    $view = Craft::$app->getView();
    $challenge = (string)Protect::getInstance()->renderProviderSettings('challenge', [
        'settings' => $settings,
        'pluginHandle' => 'app',
        'secrets' => ['hcaptchaSecret' => '••••••••'],
    ]);
    $classifier = (string)Protect::getInstance()->renderProviderSettings('classifier', ['settings' => $settings, 'pluginHandle' => 'app']);

    // Autosuggest fields keep their name and value in the script Craft registers for them, where the
    // mask's bullets are escaped.
    $scripts = json_encode($view->js, JSON_UNESCAPED_UNICODE);

    // Each provider's class sits on its own fields, with no wrapper, so Craft keeps the gap between them.
    $classes = static function(string $html, string $class): array {
        preg_match_all('/class="([^"]*\b' . preg_quote($class, '/') . '\b[^"]*)"/', $html, $matches);

        return array_map(static fn(string $value): array => preg_split('/\s+/', trim($value)), $matches[1]);
    };
    $hcaptcha = $classes($challenge, 'protect-challenge-hcaptcha');
    $turnstileOnly = array_filter($classes($challenge, 'protect-challenge-turnstile'), static fn(array $list): bool => !in_array('protect-challenge-hcaptcha', $list, true));
    $akismet = $classes($classifier, 'protect-classifier-akismet');

    expect($challenge)->toContain('name="challengeProvider"')
        ->toContain('hCaptcha Secret Key')
        ->not->toMatch('/<div class="protect-challenge-/')
        ->and($hcaptcha)->toHaveCount(3)
        ->each->toContain('field')->not->toContain('hidden')
        ->and($turnstileOnly)->toHaveCount(2)
        ->each->toContain('field')->toContain('hidden')
        ->and($akismet)->toHaveCount(4)
        ->each->toContain('hidden')
        ->and($scripts)->toContain('hcaptchaSecret')
        ->toContain('u2022')
        ->not->toContain('plain-secret')
        ->and($classifier)->toContain('name="spamClassifier"')
        ->toContain('Akismet API Key');
});

it('refuses an unknown settings section', function(): void {
    Protect::getInstance()->renderProviderSettings('passive', []);
})->throws(InvalidArgumentException::class);
