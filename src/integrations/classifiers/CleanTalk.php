<?php
namespace verbb\protect\integrations\classifiers;

use verbb\protect\Protect;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\IdentitySignalType;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use Craft;
use craft\helpers\App;

use JsonException;

use GuzzleHttp\Exception\GuzzleException;

/**
 * CleanTalk's `check_message` method. CleanTalk also takes corrections through `send_feedback`,
 * but only by the ID of the original request, which a passing result doesn't carry, so this
 * provider doesn't implement `ClassifierFeedbackInterface`.
 */
class CleanTalk extends ClassifierProvider
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'cleantalk';
    }

    public static function displayName(): string
    {
        return 'CleanTalk';
    }

    // Constants
    // =========================================================================

    private const VERSION = '1.0';

    // CleanTalk refuses these with `allow: 0`, which would otherwise read as a spam verdict.
    private const ACCOUNT_CODES = ['KEY_NOT_FOUND', 'SERVICE_DISABLED', 'TRIAL_EXPIRED'];

    // Properties
    // =========================================================================

    public string $apiKey = '';

    // Public Methods
    // =========================================================================

    public function secretAttributes(): array
    {
        return ['apiKey'];
    }

    public function classify(ProtectionContext $context): ProviderResult
    {
        $key = (string)App::parseEnv($this->apiKey);

        if ($key === '' || str_starts_with($key, '$')) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        try {
            $response = ($this->client ?? Craft::createGuzzleClient())->request('POST', 'https://moderate.cleantalk.org/api2.0', [
                'json' => $this->_params($context, $key),
                'timeout' => 5,
                'connect_timeout' => 2,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        $status = $response->getStatusCode();

        if ($status >= 500 || $status === 429) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        if ($status !== 200) {
            return new ProviderResult(CheckStatus::Error, 'provider-http-error');
        }

        try {
            $data = json_decode($response->getBody()->read(65536), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if (!is_array($data)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return $this->_interpret($data);
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey'], 'required'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    private function _params(ProtectionContext $context, string $key): array
    {
        $content = $context->content;
        $issuedAt = $context->prepared('issuedAt');

        // CleanTalk reads the referrer under its own misspelling, `REFFERRER`.
        $senderInfo = array_filter([
            'REFFERRER' => $context->request?->referrer,
            'USER_AGENT' => $context->request?->userAgent,
        ], fn($value) => $value !== null && $value !== '');

        $params = [
            'method_name' => 'check_message',
            'auth_key' => $key,
            'agent' => 'verbb-protect-' . self::VERSION,
            'message' => $content?->text,
            'sender_nickname' => $content?->authorName,
            'sender_email' => $context->identity?->get(IdentitySignalType::Email),
            'sender_ip' => $context->request?->ipAddress,
            'sender_info' => $senderInfo ? json_encode($senderInfo) : null,

            // Only a plan with a preparable check has signed timing. Without it, CleanTalk would
            // read a guessed time as a fast submission.
            'submit_time' => is_int($issuedAt) ? max(0, Protect::getInstance()->getPreparations()->now() - $issuedAt) : null,

            // Sent only when the browser proved it, because CleanTalk reads `0` as a bot signal.
            'js_on' => $context->evidence?->javascript ? 1 : null,
        ];

        return array_filter($params, fn($value) => $value !== null && $value !== '');
    }

    private function _interpret(array $data): ProviderResult
    {
        $codes = is_string($data['codes'] ?? null) ? preg_split('/\s+/', trim($data['codes']), -1, PREG_SPLIT_NO_EMPTY) : [];
        $errno = $data['errno'] ?? 0;

        if (array_intersect($codes, self::ACCOUNT_CODES) || ($data['account_status'] ?? null) === 0 || ($errno !== 0 && $errno !== '0')) {
            // CleanTalk's own explanation, not visitor data, and the only place the reason appears.
            $reason = is_string($data['errstr'] ?? null) ? $data['errstr'] : implode(' ', array_intersect($codes, self::ACCOUNT_CODES));
            $reason = substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason), 0, 200);
            Protect::error('CleanTalk returned an error response.' . ($reason !== '' ? ' ' . $reason : ''));

            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        return match ($data['allow'] ?? null) {
            0, '0' => new ProviderResult(CheckStatus::Failed, 'spam-detected'),
            1, '1' => new ProviderResult(CheckStatus::Passed, 'classification-passed'),
            default => new ProviderResult(CheckStatus::Error, 'invalid-provider-response'),
        };
    }
}
