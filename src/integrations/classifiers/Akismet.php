<?php
namespace verbb\protect\integrations\classifiers;

use verbb\protect\Protect;
use verbb\protect\base\ClassifierFeedbackInterface;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\IdentitySignalType;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use Craft;
use craft\helpers\App;
use craft\validators\UrlValidator;

use DateTimeZone;
use Throwable;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class Akismet extends ClassifierProvider implements ClassifierFeedbackInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'akismet';
    }

    public static function displayName(): string
    {
        return 'Akismet';
    }

    // Constants
    // =========================================================================

    private const VERSION = '1.0';
    private const FEEDBACK_RECEIVED = 'Thanks for making the web a better place.';

    // Properties
    // =========================================================================

    public string $apiKey = '';
    public string $blogUrl = '';
    public string $contentType = 'comment';
    public bool $testMode = false;
    public ?string $blogLanguage = null;

    // Public Methods
    // =========================================================================

    public function secretAttributes(): array
    {
        return ['apiKey'];
    }

    public function validateBlogUrl(): void
    {
        $url = App::parseEnv($this->blogUrl);

        // An unset variable is reported as a configuration error on the first request.
        if ($url === null || str_starts_with($url, '$')) {
            return;
        }

        if (!(new UrlValidator(['defaultScheme' => 'https']))->validate($url)) {
            $this->addError('blogUrl', 'The site URL is invalid.');
        }
    }

    public function requiredSignals(): array
    {
        return ['request.ipAddress'];
    }

    public function classify(ProtectionContext $context): ProviderResult
    {
        return $this->_request($context, 'comment-check');
    }

    public function feedback(ProtectionContext $context, bool $spam): ProviderResult
    {
        $result = $this->_request($context, $spam ? 'submit-spam' : 'submit-ham');
        $this->logFeedbackError($result);

        return $result;
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey', 'blogUrl', 'contentType'], 'required'];
        $rules[] = [['blogUrl'], 'validateBlogUrl'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    private function _request(ProtectionContext $context, string $operation): ProviderResult
    {
        if (!$context->request?->ipAddress) {
            return new ProviderResult(CheckStatus::Error, 'missing-required-signal');
        }

        $key = (string)App::parseEnv($this->apiKey);
        $blog = (string)App::parseEnv($this->blogUrl);

        if ($key === '' || str_starts_with($key, '$') || $blog === '' || str_starts_with($blog, '$')) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        try {
            $response = ($this->client ?? Craft::createGuzzleClient())->request('POST', 'https://rest.akismet.com/1.1/' . $operation, [
                'form_params' => $this->_params($context, $key, $blog),
                'headers' => ['User-Agent' => 'Craft/' . Craft::$app->getVersion() . ' | VerbbProtect/' . self::VERSION],
                'timeout' => 5,
                'connect_timeout' => 2,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        $status = $response->getStatusCode();

        // Akismet reports its own errors with a 200, so status codes here are transport faults.
        if ($status >= 500 || $status === 429) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        if ($status !== 200) {
            return new ProviderResult(CheckStatus::Error, 'provider-http-error');
        }

        $body = trim($response->getBody()->read(1025));
        $this->_logAlert($response);

        if ($operation !== 'comment-check') {
            return $body === self::FEEDBACK_RECEIVED ? new ProviderResult(CheckStatus::Passed, 'feedback-received') : $this->_invalidResponse($response);
        }

        return match ($body) {
            'true' => new ProviderResult(CheckStatus::Failed, 'spam-detected'),
            'false' => new ProviderResult(CheckStatus::Passed, 'classification-passed'),
            default => $this->_invalidResponse($response),
        };
    }

    private function _params(ProtectionContext $context, string $key, string $blog): array
    {
        $content = $context->content;

        $params = [
            'api_key' => $key,
            'blog' => $blog,
            'blog_charset' => 'UTF-8',
            'blog_lang' => $this->blogLanguage ?? substr((string)Craft::$app->language, 0, 2),
            'user_ip' => $context->request->ipAddress,
            'user_agent' => $context->request->userAgent,
            'referrer' => $context->request->referrer,
            'permalink' => $context->subject?->url,
            'comment_type' => $this->contentType,
            'comment_content' => $content?->text,
            'comment_author' => $content?->authorName,
            'comment_author_url' => $content?->authorUrl,
            'comment_author_email' => $context->identity?->get(IdentitySignalType::Email),
            'comment_date_gmt' => $content?->dateCreated?->setTimezone(new DateTimeZone('UTC'))->format('c'),
            'recheck_reason' => $content?->edited ? 'edit' : null,
            'is_test' => $this->testMode ? 'true' : null,
        ];

        // Akismet takes the honeypot's name plus its value under that same name.
        $honeypot = $context->prepared('honeypot');

        if (is_string($honeypot)) {
            $params['honeypot_field_name'] = $honeypot;
            $params[$honeypot] = $context->evidence?->honeypot[$honeypot] ?? '';
        }

        return array_filter($params, fn($value) => $value !== null);
    }

    private function _invalidResponse(ResponseInterface $response): ProviderResult
    {
        // A revoked key or a malformed call comes back here. The debug header is Akismet's own
        // diagnosis, not visitor data, and it's the only place the reason appears.
        $help = substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $response->getHeaderLine('X-akismet-debug-help')), 0, 200);
        Protect::error('Akismet returned an error response.' . ($help !== '' ? ' ' . $help : ''));

        return new ProviderResult(CheckStatus::Error, 'provider-configuration');
    }

    /**
     * Whether Akismet keeps sending verdicts alongside an account alert is undocumented, so a
     * valid verdict is kept. Logged once a day per alert code rather than per comment.
     */
    private function _logAlert(ResponseInterface $response): void
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', $response->getHeaderLine('X-akismet-alert-code'));

        if ($code === '' && !$response->hasHeader('X-akismet-alert-msg')) {
            return;
        }

        try {
            if (!Craft::$app->getCache()->add('protect:akismet-alert:' . $code, true, 86400)) {
                return;
            }
        } catch (Throwable) {
            // A cache outage shouldn't stop classification, and logging once more is harmless.
        }

        Protect::warning('Akismet reported an account alert' . ($code !== '' ? ' (code ' . $code . ').' : '.'));
    }
}
