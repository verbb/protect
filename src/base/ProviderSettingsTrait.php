<?php
namespace verbb\protect\base;

use verbb\protect\integrations\challenges\FriendlyCaptcha;
use verbb\protect\integrations\challenges\Hcaptcha;
use verbb\protect\integrations\challenges\RecaptchaV3;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\integrations\classifiers\CleanTalk;
use verbb\protect\integrations\classifiers\OopSpam;

use Craft;

/**
 * The challenge provider and spam classifier a plugin offers in its settings, with each built-in
 * provider's keys. Use it on a plugin's settings model so every Verbb plugin names, validates and
 * builds providers the same way, and render the matching fields with `Protect::renderProviderSettings()`.
 * Where the settings are stored, and who may change them, stays with the plugin.
 */
trait ProviderSettingsTrait
{
    // Static Methods
    // =========================================================================

    public static function challengeProviders(): array
    {
        return [self::CHALLENGE_NONE, self::CHALLENGE_TURNSTILE, self::CHALLENGE_RECAPTCHA, self::CHALLENGE_HCAPTCHA, self::CHALLENGE_FRIENDLY_CAPTCHA];
    }

    public static function spamClassifiers(): array
    {
        return [self::CLASSIFIER_NONE, self::CLASSIFIER_AKISMET, self::CLASSIFIER_CLEANTALK, self::CLASSIFIER_OOPSPAM];
    }


    // Constants
    // =========================================================================

    public const CHALLENGE_NONE = '';
    public const CHALLENGE_TURNSTILE = 'turnstile';
    public const CHALLENGE_RECAPTCHA = 'recaptcha';
    public const CHALLENGE_HCAPTCHA = 'hcaptcha';
    public const CHALLENGE_FRIENDLY_CAPTCHA = 'friendly-captcha';

    public const CLASSIFIER_NONE = '';
    public const CLASSIFIER_AKISMET = 'akismet';
    public const CLASSIFIER_CLEANTALK = 'cleantalk';
    public const CLASSIFIER_OOPSPAM = 'oopspam';

    // The settings that may name an environment variable or alias. A site key is sent to every visitor,
    // so a plugin that lets non-admins edit these should stop them naming one.
    public const PROVIDER_ENV_KEYS = [
        'turnstileKey',
        'turnstileSecret',
        'recaptchaKey',
        'recaptchaSecret',
        'hcaptchaKey',
        'hcaptchaSecret',
        'friendlyCaptchaKey',
        'friendlyCaptchaSecret',
        'akismetApiKey',
        'akismetBlogUrl',
        'cleantalkApiKey',
        'oopspamApiKey',
    ];

    // The settings that hold a credential, which a settings page shouldn't show back as plain text.
    public const PROVIDER_SECRET_KEYS = [
        'turnstileSecret',
        'recaptchaSecret',
        'hcaptchaSecret',
        'friendlyCaptchaSecret',
        'akismetApiKey',
        'cleantalkApiKey',
        'oopspamApiKey',
    ];


    // Properties
    // =========================================================================

    // Which challenge provider asks visitors to prove they're a person, or empty for none.
    public string $challengeProvider = self::CHALLENGE_NONE;
    public ?string $turnstileKey = null;
    public ?string $turnstileSecret = null;
    public ?string $recaptchaKey = null;
    public ?string $recaptchaSecret = null;
    public float $recaptchaMinScore = 0.5;
    public ?string $hcaptchaKey = null;
    public ?string $hcaptchaSecret = null;
    public ?string $friendlyCaptchaKey = null;
    public ?string $friendlyCaptchaSecret = null;
    // Friendly Captcha's `global` or `eu` service.
    public string $friendlyCaptchaEndpoint = 'global';
    public bool $challengeSendIp = false;

    // Which service classifies submissions as spam, or empty for none. A plan has one classifier.
    public string $spamClassifier = self::CLASSIFIER_NONE;
    public ?string $akismetApiKey = null;
    public ?string $akismetBlogUrl = null;
    public bool $akismetTestMode = false;
    public ?string $cleantalkApiKey = null;
    public ?string $oopspamApiKey = null;
    // OOPSpam's score from 1 to 6 at which a submission is spam.
    public int $oopspamSpamThreshold = 3;


    // Public Methods
    // =========================================================================

    public function hasChallengeProvider(): bool
    {
        return $this->challengeProvider !== self::CHALLENGE_NONE;
    }

    public function hasSpamClassifier(): bool
    {
        return $this->spamClassifier !== self::CLASSIFIER_NONE;
    }

    /**
     * The chosen challenge provider. With none chosen, reCAPTCHA stands in with its own keys, so a plan
     * can carry a switched-off Challenge and keep the same checks whatever is chosen. Check
     * `hasChallengeProvider()` to decide whether it's enabled. `$keys` replaces the site and secret key,
     * such as for a front end on another origin with its own.
     */
    public function createChallengeProvider(string $action = '', array $hostnames = [], ?array $keys = null): ChallengeProvider
    {
        $config = match ($this->challengeProvider) {
            self::CHALLENGE_TURNSTILE => [
                'class' => Turnstile::class,
                'siteKey' => (string)$this->turnstileKey,
                'secretKey' => (string)$this->turnstileSecret,
                'expectedAction' => $action,
            ],
            self::CHALLENGE_HCAPTCHA => [
                'class' => Hcaptcha::class,
                'siteKey' => (string)$this->hcaptchaKey,
                'secretKey' => (string)$this->hcaptchaSecret,
            ],
            self::CHALLENGE_FRIENDLY_CAPTCHA => [
                'class' => FriendlyCaptcha::class,
                'siteKey' => (string)$this->friendlyCaptchaKey,
                'secretKey' => (string)$this->friendlyCaptchaSecret,
                'endpoint' => $this->friendlyCaptchaEndpoint,
            ],
            default => [
                'class' => RecaptchaV3::class,
                'siteKey' => (string)$this->recaptchaKey,
                'secretKey' => (string)$this->recaptchaSecret,
                'expectedAction' => $action,
                'minimumScore' => $this->recaptchaMinScore,
            ],
        };

        return Craft::createObject([
            ...$config,
            'siteKey' => $keys['siteKey'] ?? $config['siteKey'],
            'secretKey' => $keys['secretKey'] ?? $config['secretKey'],
            'hostnames' => $hostnames,
            'sendIp' => $this->challengeSendIp,
        ]);
    }

    /**
     * The chosen spam classifier, or null for none. Akismet reports against `$siteUrl` unless a site URL
     * is set. `$contentType` is Akismet's name for what's being checked, such as `comment` or `forum-post`.
     */
    public function createSpamClassifier(string $siteUrl, string $contentType = 'comment'): ?ClassifierProvider
    {
        return match ($this->spamClassifier) {
            self::CLASSIFIER_AKISMET => Craft::createObject([
                'class' => Akismet::class,
                'apiKey' => (string)$this->akismetApiKey,
                'blogUrl' => $this->akismetBlogUrl ?: $siteUrl,
                'contentType' => $contentType,
                'testMode' => $this->akismetTestMode,
            ]),
            self::CLASSIFIER_CLEANTALK => Craft::createObject([
                'class' => CleanTalk::class,
                'apiKey' => (string)$this->cleantalkApiKey,
            ]),
            self::CLASSIFIER_OOPSPAM => Craft::createObject([
                'class' => OopSpam::class,
                'apiKey' => (string)$this->oopspamApiKey,
                'spamThreshold' => $this->oopspamSpamThreshold,
            ]),
            default => null,
        };
    }


    // Protected Methods
    // =========================================================================

    /**
     * Rules for the provider settings. Keys are only required for the chosen provider, and only while
     * `$active` says protection is in use, so a plugin with protection switched off can still save.
     */
    protected function defineProviderRules(?callable $active = null): array
    {
        $active ??= fn() => true;

        return [
            [['challengeProvider'], 'in', 'range' => self::challengeProviders()],
            [['turnstileKey', 'turnstileSecret'], 'required', 'when' => fn() => $active() && $this->challengeProvider === self::CHALLENGE_TURNSTILE],
            [['recaptchaKey', 'recaptchaSecret'], 'required', 'when' => fn() => $active() && $this->challengeProvider === self::CHALLENGE_RECAPTCHA],
            [['hcaptchaKey', 'hcaptchaSecret'], 'required', 'when' => fn() => $active() && $this->challengeProvider === self::CHALLENGE_HCAPTCHA],
            [['friendlyCaptchaKey', 'friendlyCaptchaSecret'], 'required', 'when' => fn() => $active() && $this->challengeProvider === self::CHALLENGE_FRIENDLY_CAPTCHA],
            [['recaptchaMinScore'], 'number', 'min' => 0, 'max' => 1],
            [['friendlyCaptchaEndpoint'], 'in', 'range' => ['global', 'eu']],
            [['spamClassifier'], 'in', 'range' => self::spamClassifiers()],
            [['akismetApiKey'], 'required', 'when' => fn() => $active() && $this->spamClassifier === self::CLASSIFIER_AKISMET],
            [['cleantalkApiKey'], 'required', 'when' => fn() => $active() && $this->spamClassifier === self::CLASSIFIER_CLEANTALK],
            [['oopspamApiKey'], 'required', 'when' => fn() => $active() && $this->spamClassifier === self::CLASSIFIER_OOPSPAM],
            [['oopspamSpamThreshold'], 'integer', 'min' => 1, 'max' => 6],
            [['akismetBlogUrl'], 'url'],
        ];
    }
}
