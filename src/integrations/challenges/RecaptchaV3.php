<?php
namespace verbb\protect\integrations\challenges;

use verbb\protect\base\ChallengeProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\models\ProviderResult;

class RecaptchaV3 extends ChallengeProvider
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'recaptcha-v3';
    }

    public static function displayName(): string
    {
        return 'reCAPTCHA v3';
    }

    // Properties
    // =========================================================================

    public float $minimumScore = 0.5;

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['minimumScore'], 'number', 'min' => 0, 'max' => 1];
        $rules[] = [['expectedAction'], 'match', 'pattern' => '/^[a-zA-Z0-9_\/]+$/'];

        return $rules;
    }

    protected function siteVerifyUrl(): string
    {
        return 'https://www.google.com/recaptcha/api/siteverify';
    }

    protected function interpret(array $data): ProviderResult
    {
        // A reCAPTCHA v2 key succeeds with neither field. That's a wrong key, not a bad response.
        if ($data['success'] && !isset($data['action']) && !isset($data['score'])) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        $result = parent::interpret($data);

        if ($result->status !== CheckStatus::Passed) {
            return $result;
        }

        $score = $data['score'] ?? null;

        if ((!is_float($score) && !is_int($score)) || $score < 0 || $score > 1) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if ($score < $this->minimumScore) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-score', ['score' => $score, 'threshold' => $this->minimumScore]);
        }

        return $result;
    }
}
