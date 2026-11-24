<?php
namespace verbb\protect\base;

use verbb\protect\enums\CheckStatus;
use verbb\protect\errors\InvalidPlanException;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use Craft;
use craft\base\Component;
use craft\helpers\App;

use JsonException;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

abstract class ChallengeProvider extends Component implements ChallengeProviderInterface
{
    // Traits
    // =========================================================================

    use FingerprintTrait;

    // Properties
    // =========================================================================

    public ?ClientInterface $client = null;
    public string $siteKey = '';
    public string $secretKey = '';
    public string $expectedAction = '';
    public array $hostnames = [];
    public bool $sendIp = false;

    // Public Methods
    // =========================================================================

    public function secretAttributes(): array
    {
        return ['secretKey'];
    }

    public function prepare(ProtectionContext $context): array
    {
        $siteKey = (string)App::parseEnv($this->siteKey);

        // An unset environment variable passes `required` on `$VAR`, then fails only in the browser.
        if ($siteKey === '' || str_starts_with($siteKey, '$')) {
            throw new InvalidPlanException('Invalid configuration for ' . static::handle() . ': the site key is empty.', ['siteKey' => ['The site key is empty.']]);
        }

        return ['provider' => static::handle(), 'siteKey' => $siteKey, 'action' => $this->expectedAction];
    }

    public function verify(string $token, ProtectionContext $context): ProviderResult
    {
        if ($token === '' || strlen($token) > $this->maxTokenLength()) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-token-missing');
        }

        $secret = (string)App::parseEnv($this->secretKey);

        if ($secret === '' || str_starts_with($secret, '$')) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        $params = ['secret' => $secret, 'response' => $token];

        if ($this->sendIp && $context->request?->ipAddress) {
            $params['remoteip'] = $context->request->ipAddress;
        }

        return $this->siteVerify($params);
    }

    // Protected Methods
    // =========================================================================

    abstract protected function siteVerifyUrl(): string;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['siteKey', 'secretKey', 'hostnames'], 'required'];

        if ($this->requiresAction()) {
            $rules[] = [['expectedAction'], 'required'];
        }

        $rules[] = [['hostnames'], 'each', 'rule' => ['match', 'pattern' => '/^[a-zA-Z0-9.-]+$/']];

        return $rules;
    }

    /**
     * Whether the service binds a token to an action. One that doesn't, such as hCaptcha, has no
     * action to check, so `expectedAction` isn't required and `interpret()` must not demand it.
     */
    protected function requiresAction(): bool
    {
        return true;
    }

    protected function maxTokenLength(): int
    {
        return 8192;
    }

    protected function maxAttempts(): int
    {
        return 1;
    }

    protected function siteVerify(array $params): ProviderResult
    {
        $client = $this->client ?? Craft::createGuzzleClient();
        $attempts = max(1, $this->maxAttempts());

        for ($attempt = 1; ; $attempt++) {
            // A retry gets a tighter budget, so the worst case stays about 11 seconds in the request path.
            $result = $this->_attempt($client, $params, $attempt === 1 ? 5 : 3, $attempt === 1 ? 2 : 1);

            if ($result->code !== 'provider-unavailable' || $attempt >= $attempts) {
                return $result;
            }
        }
    }

    /**
     * The request options for one siteverify call. Most services take a form body, and one that
     * wants JSON or a credential in a header overrides this.
     */
    protected function requestOptions(array $params): array
    {
        return ['form_params' => $params];
    }

    /**
     * Turns a status other than 200 into a result, for services that answer some errors with one.
     */
    protected function interpretStatus(int $status): ProviderResult
    {
        return new ProviderResult(CheckStatus::Error, $status >= 500 ? 'provider-unavailable' : 'provider-http-error');
    }

    /**
     * Turns a well-formed siteverify response into a result. Providers extend this for their
     * own fields. Both providers enforce token lifetime and single use themselves, so there's
     * no local `challenge_ts` window: it could only reject a legitimate visitor on clock drift.
     */
    protected function interpret(array $data): ProviderResult
    {
        if (!$data['success']) {
            return $this->interpretFailure($data['error-codes'] ?? []);
        }

        if (!is_string($data['action'] ?? null) || !is_string($data['hostname'] ?? null) || !is_string($data['challenge_ts'] ?? null)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if ($data['action'] !== $this->expectedAction || !in_array(strtolower($data['hostname']), array_map('strtolower', $this->hostnames), true)) {
            return new ProviderResult(CheckStatus::Failed, 'challenge-context-mismatch');
        }

        return new ProviderResult(CheckStatus::Passed, 'challenge-passed');
    }

    protected function interpretFailure(mixed $codes): ProviderResult
    {
        if (!is_array($codes)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if (in_array('internal-error', $codes, true)) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        if (array_intersect($codes, ['missing-input-secret', 'invalid-input-secret', 'bad-request'])) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        if (!array_intersect($codes, ['missing-input-response', 'invalid-input-response', 'timeout-or-duplicate'])) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return new ProviderResult(CheckStatus::Failed, 'challenge-rejected');
    }

    // Private Methods
    // =========================================================================

    private function _attempt(ClientInterface $client, array $params, int $timeout, int $connectTimeout): ProviderResult
    {
        try {
            $response = $client->request('POST', $this->siteVerifyUrl(), [
                ...$this->requestOptions($params),
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->interpretStatus($response->getStatusCode());
            }

            $data = json_decode($response->getBody()->read(32769), true, 16, JSON_THROW_ON_ERROR);
        } catch (GuzzleException) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        } catch (JsonException) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        if (!is_array($data) || !is_bool($data['success'] ?? null)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return $this->interpret($data);
    }
}
