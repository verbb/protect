<?php
namespace verbb\protect\integrations\challenges;

use verbb\protect\base\ChallengeProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\models\ProviderResult;

use craft\helpers\App;

class Hcaptcha extends ChallengeProvider
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'hcaptcha';
    }

    public static function displayName(): string
    {
        return 'hCaptcha';
    }

    // Properties
    // =========================================================================

    public ?float $maximumScore = null;

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['maximumScore'], 'number', 'min' => 0, 'max' => 1];

        return $rules;
    }

    protected function requiresAction(): bool
    {
        return false;
    }

    protected function siteVerifyUrl(): string
    {
        return 'https://api.hcaptcha.com/siteverify';
    }

    protected function siteVerify(array $params): ProviderResult
    {
        // hCaptcha then refuses a token issued for another site key, including one from a page
        // that reuses this site's public key with its own account.
        $params['sitekey'] = (string)App::parseEnv($this->siteKey);

        return parent::siteVerify($params);
    }

    /**
     * hCaptcha has no actions, so a token is bound by its site key and hostname. Enterprise
     * accounts also get a risk score, where higher means more likely to be a bot.
     */
    protected function interpret(array $data): ProviderResult
    {
        if (!$data['success']) {
            return $this->interpretFailure($data['error-codes'] ?? []);
        }

        if (!is_string($data['hostname'] ?? null)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        // hCaptcha reports `not-provided` in place of the hostname when it sheds load. That confirms
        // nothing, so it fails like any hostname that isn't listed, as Turnstile's unconfirmed tokens do.
        $hostname = strtolower($data['hostname']);

        if (!in_array($hostname, array_map('strtolower', $this->hostnames), true)) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-context-mismatch');
        }

        if ($this->maximumScore === null) {
            return new ProviderResult(CheckStatus::Passed, 'challenge-passed');
        }

        $score = $data['score'] ?? null;

        // A threshold without a score means the account isn't on Enterprise, which the site owner fixes.
        if ($score === null) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        if ((!is_float($score) && !is_int($score)) || $score < 0 || $score > 1) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if ($score > $this->maximumScore) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-score', ['score' => $score, 'threshold' => $this->maximumScore]);
        }

        return new ProviderResult(CheckStatus::Passed, 'challenge-passed');
    }

    protected function interpretFailure(mixed $codes): ProviderResult
    {
        if (!is_array($codes)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if (array_intersect($codes, ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'not-using-dummy-passcode', 'bad-request', 'missing-remoteip', 'invalid-remoteip'])) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        if (array_intersect($codes, ['missing-input-response', 'invalid-input-response', 'expired-input-response', 'already-seen-response', 'invalid-or-already-seen-response'])) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-rejected');
        }

        return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
    }
}
