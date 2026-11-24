<?php
namespace verbb\protect\integrations\challenges;

use verbb\protect\base\ChallengeProvider;
use verbb\protect\models\ProviderResult;

use craft\helpers\StringHelper;

class Turnstile extends ChallengeProvider
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'turnstile';
    }

    public static function displayName(): string
    {
        return 'Cloudflare Turnstile';
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['expectedAction'], 'match', 'pattern' => '/^[a-zA-Z0-9_-]{1,32}$/'];

        return $rules;
    }

    protected function siteVerifyUrl(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    }

    protected function maxTokenLength(): int
    {
        return 2048;
    }

    protected function maxAttempts(): int
    {
        return 2;
    }

    protected function siteVerify(array $params): ProviderResult
    {
        // Cloudflare treats a retry with the same key as the same verification, so the
        // single-use token survives a transient failure.
        $params['idempotency_key'] = StringHelper::UUID();

        return parent::siteVerify($params);
    }
}
