<?php
namespace verbb\protect\integrations\classifiers;

use verbb\protect\base\ClassifierFeedbackInterface;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\IdentitySignalType;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use Craft;
use craft\helpers\App;

use JsonException;

use GuzzleHttp\Exception\GuzzleException;

class OopSpam extends ClassifierProvider implements ClassifierFeedbackInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'oopspam';
    }

    public static function displayName(): string
    {
        return 'OOPSpam';
    }

    // Properties
    // =========================================================================

    public string $apiKey = '';
    public int $spamThreshold = 3;

    // Off by default, unlike OOPSpam's API, which scores anything of 20 characters or fewer as
    // spam and would hold every short "Thanks!" comment.
    public bool $checkForLength = false;
    public bool $blockTempEmail = false;
    public bool $blockVPN = false;
    public bool $blockDC = false;
    public bool $urlFriendly = false;
    public array $allowedLanguages = [];
    public array $allowedCountries = [];
    public array $blockedCountries = [];
    public bool $logIt = false;
    public string $source = '';

    // Public Methods
    // =========================================================================

    public function secretAttributes(): array
    {
        return ['apiKey'];
    }

    public function requiredSignals(): array
    {
        // OOPSpam can only apply a country rule to an address.
        return $this->allowedCountries || $this->blockedCountries ? ['request.ipAddress'] : [];
    }

    public function classify(ProtectionContext $context): ProviderResult
    {
        $data = $this->_post('https://api.oopspam.com/v1/spamdetection', $this->_body($context));

        if ($data instanceof ProviderResult) {
            return $data;
        }

        $score = $data['Score'] ?? null;

        if ((!is_int($score) && !is_float($score)) || $score < 0 || $score > 6) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if ($score >= $this->spamThreshold) {
            return new ProviderResult(CheckStatus::Failed, 'spam-detected', ['score' => $score, 'threshold' => $this->spamThreshold]);
        }

        return new ProviderResult(CheckStatus::Passed, 'classification-passed');
    }

    public function feedback(ProtectionContext $context, bool $spam): ProviderResult
    {
        $data = $this->_post('https://api.oopspam.com/v1/spamdetection/report', [...$this->_body($context), 'shouldBeSpam' => $spam]);

        if (!$data instanceof ProviderResult) {
            $data = ($data['message'] ?? null) === 'success'
                ? new ProviderResult(CheckStatus::Passed, 'feedback-received')
                : new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        $this->logFeedbackError($data);

        return $data;
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey'], 'required'];
        $rules[] = [['spamThreshold'], 'integer', 'min' => 1, 'max' => 6];
        $rules[] = [['allowedLanguages', 'allowedCountries', 'blockedCountries'], 'each', 'rule' => ['match', 'pattern' => '/^[a-zA-Z]{2}$/']];

        // OOPSpam only keeps a dashboard log for a request that names its source.
        $rules[] = [['source'], 'required', 'when' => fn() => $this->logIt];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    private function _body(ProtectionContext $context): array
    {
        $body = [
            'content' => $context->content?->text,
            'senderIP' => $context->request?->ipAddress,
            'email' => $context->identity?->get(IdentitySignalType::Email),
            'checkForLength' => $this->checkForLength,
            'blockTempEmail' => $this->blockTempEmail,
            'blockVPN' => $this->blockVPN,
            'blockDC' => $this->blockDC,
            'urlFriendly' => $this->urlFriendly,
            'allowedLanguages' => array_map('strtolower', $this->allowedLanguages) ?: null,
            'allowedCountries' => array_map('strtolower', $this->allowedCountries) ?: null,
            'blockedCountries' => array_map('strtolower', $this->blockedCountries) ?: null,
            'logIt' => $this->logIt,
            'source' => $this->logIt ? $this->source : null,
        ];

        return array_filter($body, fn($value) => $value !== null && $value !== '');
    }

    private function _post(string $url, array $body): array|ProviderResult
    {
        $key = (string)App::parseEnv($this->apiKey);

        if ($key === '' || str_starts_with($key, '$')) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        try {
            $response = ($this->client ?? Craft::createGuzzleClient())->request('POST', $url, [
                'json' => $body,
                'headers' => ['X-Api-Key' => $key],
                'timeout' => 5,
                'connect_timeout' => 2,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        $status = $response->getStatusCode();

        // OOPSpam's quota is monthly, so an exhausted quota also arrives as a 429 and looks like
        // an outage until it resets.
        if ($status === 429 || $status >= 500) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        // A wrong key is a 401, and a 400 means a setting OOPSpam won't accept, such as an unknown
        // language code. Neither is the visitor's doing.
        if ($status === 400 || $status === 401 || $status === 403) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        if ($status !== 200) {
            return new ProviderResult(CheckStatus::Error, 'provider-http-error');
        }

        try {
            $data = json_decode($response->getBody()->read(65536), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return is_array($data) ? $data : new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
    }
}
