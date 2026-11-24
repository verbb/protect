<?php
namespace verbb\protect\integrations\challenges;

use verbb\protect\base\ChallengeProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use craft\helpers\App;

/**
 * Friendly Captcha v2. v1 used a different widget, endpoint and response, and Friendly Captcha
 * only offers v2 for new accounts, so v1 isn't supported.
 */
class FriendlyCaptcha extends ChallengeProvider
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'friendly-captcha';
    }

    public static function displayName(): string
    {
        return 'Friendly Captcha';
    }

    // Properties
    // =========================================================================

    public string $endpoint = 'global';

    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array
    {
        // The widget must solve its puzzle on the same endpoint the server verifies it with.
        return [...parent::prepare($context), 'endpoint' => $this->endpoint];
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['endpoint'], 'in', 'range' => ['global', 'eu']];

        return $rules;
    }

    protected function requiresAction(): bool
    {
        return false;
    }

    protected function siteVerifyUrl(): string
    {
        return 'https://' . ($this->endpoint === 'eu' ? 'eu' : 'global') . '.frcapi.com/api/v2/captcha/siteverify';
    }

    /**
     * Friendly Captcha takes the API key in a header and a JSON body. It doesn't use the visitor's
     * IP address, so `sendIp` has no effect, and the site key binds the token to this widget.
     */
    protected function requestOptions(array $params): array
    {
        return [
            'headers' => ['X-API-Key' => $params['secret']],
            'json' => ['response' => $params['response'], 'sitekey' => (string)App::parseEnv($this->siteKey)],
        ];
    }

    protected function interpretStatus(int $status): ProviderResult
    {
        // Friendly Captcha answers a missing or wrong API key with a 401 and a bad site key or
        // request with a 400. A visitor's own token never produces either.
        if ($status === 400 || $status === 401) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        return parent::interpretStatus($status);
    }

    protected function interpret(array $data): ProviderResult
    {
        if (!$data['success']) {
            return $this->interpretFailure($data['error']['error_code'] ?? null);
        }

        $origin = $data['data']['challenge']['origin'] ?? null;

        if (!is_string($origin)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        // Friendly Captcha leaves the origin empty when it doesn't know it. That confirms nothing, so it
        // fails like an origin that isn't listed, as Turnstile's unconfirmed tokens do.
        $host = $origin === '' ? null : parse_url($origin, PHP_URL_HOST);

        if (!is_string($host) || !in_array(strtolower($host), array_map('strtolower', $this->hostnames), true)) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-context-mismatch');
        }

        return new ProviderResult(CheckStatus::Passed, 'challenge-passed');
    }

    protected function interpretFailure(mixed $codes): ProviderResult
    {
        return match ($codes) {
            'response_invalid', 'response_timeout', 'response_duplicate' => new ProviderResult(CheckStatus::Failed, 'challenge-rejected'),
            'auth_required', 'auth_invalid', 'sitekey_invalid', 'bad_request' => new ProviderResult(CheckStatus::Error, 'provider-configuration'),
            default => new ProviderResult(CheckStatus::Error, 'invalid-provider-response'),
        };
    }
}
