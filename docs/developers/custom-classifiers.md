# Custom Classifiers

A classifier looks at the content of an attempt and says whether it's spam. Akismet, CleanTalk and OOPSpam are built in, and a custom classifier lets you add your own rules or another spam service alongside them or instead of them. Your classifier reports what it finds, the same way the built-in ones do, and deciding whether to refuse, slow down or hold an attempt stays with the plugin that uses it.

## Create the Classifier Class

Extend `verbb\protect\base\ClassifierProvider`. Suppose your feedback form keeps receiving messages with the same giveaway phrases. This classifier flags any message containing one of them. Phrase matching alone won't catch much spam, but it shows the shape every classifier shares. Put the class in your plugin's own namespace:

```php
namespace example\protection;

use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

class PhraseClassifier extends ClassifierProvider
{
    public array $phrases = [];

    public static function handle(): string
    {
        return 'example-phrases';
    }

    public static function displayName(): string
    {
        return 'Phrase Indicators';
    }

    public function classify(ProtectionContext $context): ProviderResult
    {
        $text = mb_strtolower($context->content?->text ?? '');

        foreach ($this->phrases as $phrase) {
            if (str_contains($text, mb_strtolower($phrase))) {
                return new ProviderResult(CheckStatus::Failed, 'phrase-indicator');
            }
        }

        return new ProviderResult(CheckStatus::Passed, 'no-phrase-indicator');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['phrases'], 'each', 'rule' => ['string', 'min' => 1, 'skipOnEmpty' => false]];

        return $rules;
    }
}
```

`classify()` returns a `ProviderResult` with a status and a machine-readable code. `Failed` means the content looks like spam, and Protect turns it into a `SpamDetected` finding with your code. `Passed` means it doesn't. Return `Error` when the classifier couldn't reach a conclusion, never a pass, so an outage isn't mistaken for clean content. An error with the code `provider-configuration` becomes a `ConfigurationError` finding, `invalid-provider-response` becomes `InvalidProviderResponse`, `missing-required-signal` becomes `MissingRequiredSignal`, and any other error code becomes `ProviderUnavailable`. Keep submitted text, credentials and anything the service sent back out of your codes.

`defineRules()` validates the settings, so a plan with an invalid classifier throws an `InvalidPlanException` when it's prepared or checked. Validate your settings screen with the same rules when it's saved.

## Register the Classifier

Protect finds classifiers through the `registerClassifierTypes` event. Register your class from your module or plugin’s `init()` method.

```php
use example\protection\PhraseClassifier;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Classifiers;
use yii\base\Event;

Event::on(
    Classifiers::class,
    Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES,
    static function(RegisterComponentTypesEvent $event): void {
        $event->types[] = PhraseClassifier::class;
    },
);
```

Protect only runs classes that have been registered, so a plan containing an unregistered classifier fails validation with an `InvalidComponentException`. The rules for handles and duplicate registrations are in [Extending Protect](extending-protect.md#register-your-classes).

## Add the Classifier to a Plan

Add your classifier to a plan with a `Classification` check. In a real plugin, the plan goes in your protection service and the check in your controller, but they're together here so you can see the whole flow:

```php
use example\protection\PhraseClassifier;
use verbb\protect\Protect;
use verbb\protect\checks\Classification;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-feedback'))
    ->add(new Classification([
        'provider' => new PhraseClassifier(['phrases' => ['guaranteed prize']]),
    ]));

$result = Protect::getInstance()->check(new ProtectionContext(
    action: 'example.feedback.create',
    content: new ProtectionContent('Claim your guaranteed prize'),
), $plan);
```

This message contains one of the phrases, so the result has a `SpamDetected` finding with the code `phrase-indicator`. Classification is skipped for a context with no content, so actions without text, such as votes, can share the plan.

## Write a Remote Classifier

Most classifiers are services you call over HTTP. This example is a shorter version of Protect's own `OopSpam` provider, calling [OOPSpam](https://www.oopspam.com/docs), which scores a message from 0 to 6 using its content, the sender's IP address and email, and its own denylists. Use it as a starting point for a service Protect doesn't include. It shows what every remote provider has to get right, which is keeping the API key secret, putting a time limit on the request, and telling spam apart from an outage or a configuration problem, because your plugin responds to each differently.

```php
namespace example\protection;

use verbb\protect\base\ClassifierFeedbackInterface;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\IdentitySignalType;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use Craft;
use craft\helpers\App;

use GuzzleHttp\Exception\GuzzleException;

use JsonException;

class OopSpam extends ClassifierProvider implements ClassifierFeedbackInterface
{
    public string $apiKey = '';

    // OOPSpam scores from 0 to 6 and recommends treating 3 or more as spam.
    public int $threshold = 3;

    // OOPSpam scores anything of 20 characters or fewer as spam unless this is off.
    public bool $checkForLength = false;

    public array $allowedLanguages = [];

    public static function handle(): string
    {
        return 'example-oopspam';
    }

    public static function displayName(): string
    {
        return 'OOPSpam';
    }

    public function secretAttributes(): array
    {
        return ['apiKey'];
    }

    public function classify(ProtectionContext $context): ProviderResult
    {
        $data = $this->_post('https://api.oopspam.com/v1/spamdetection', $this->_body($context));

        if ($data instanceof ProviderResult) {
            return $data;
        }

        $score = $data['Score'] ?? null;

        if (!is_int($score) && !is_float($score)) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return $score >= $this->threshold
            ? new ProviderResult(CheckStatus::Failed, 'spam-detected', ['score' => $score])
            : new ProviderResult(CheckStatus::Passed, 'classification-passed', ['score' => $score]);
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

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey'], 'required'];
        $rules[] = [['threshold'], 'integer', 'min' => 0, 'max' => 6];

        return $rules;
    }

    private function _body(ProtectionContext $context): array
    {
        return array_filter([
            'content' => $context->content?->text,
            'senderIP' => $context->request?->ipAddress,
            'email' => $context->identity?->get(IdentitySignalType::Email),
            'checkForLength' => $this->checkForLength,
            'allowedLanguages' => $this->allowedLanguages ?: null,
        ], fn($value) => $value !== null && $value !== '');
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

        if ($status === 401) {
            return new ProviderResult(CheckStatus::Error, 'provider-configuration');
        }

        if ($status === 429 || $status >= 500) {
            return new ProviderResult(CheckStatus::Error, 'provider-unavailable');
        }

        if ($status !== 200) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        try {
            $data = json_decode($response->getBody()->read(65536), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
        }

        return is_array($data) ? $data : new ProviderResult(CheckStatus::Error, 'invalid-provider-response');
    }
}
```

The handle starts with `example-`, your plugin's handle, so it can't clash with the built-in `oopspam`. `apiKey` accepts an environment expression such as `$OOPSPAM_API_KEY`, which `App::parseEnv()` resolves when the check runs. Listing it in `secretAttributes()` keeps it out of the plan's fingerprint, and classifiers never appear in browser manifests or results. An unset variable, like a key OOPSpam rejects with a 401, returns `provider-configuration`, which becomes a `ConfigurationError` finding, so a plugin that refuses submissions on that finding won't let them through because of a mistake the site owner needs to fix.

OOPSpam recommends treating a score of 3 or more as spam, so `threshold` defaults to that. A message scored as spam keeps its score in the finding's metadata, where a moderation screen or a log can show it, while a passing score is discarded, because a passed check has no finding to carry it. A finding only keeps numeric `limit`, `window`, `retryAfter`, `score` and `threshold` metadata, and drops any other keys, so name values you want to keep accordingly. `checkForLength` defaults to off here because OOPSpam scores any message of 20 characters or fewer as spam when it's on, which would hold every short “Thanks!” comment. Turn it on for forms where a short message is suspicious, such as a contact form. `allowedLanguages` takes ISO 639-1 codes such as `['en']`, and OOPSpam raises the score of a message in any other language.

The request gives up after two seconds if it can't connect and five seconds overall, and never follows redirects. A connection failure, a 5xx response and a 429 all return `provider-unavailable`, which your plugin's outage policy handles. OOPSpam's quota is monthly, so an exhausted quota also arrives as a 429 and looks like an outage until it resets. Protect's log records each error, so watch it if you're close to your limit. Any other unexpected status or unreadable body returns `invalid-provider-response`.

A classifier that can't work without a particular signal can say so by overriding `requiredSignals()`, which returns names such as `request.ipAddress`, `request.userAgent` or `identity.email`. When any of them is missing, `Classification` returns a `MissingRequiredSignal` error without calling `classify()`. Akismet requires `request.ipAddress` this way. OOPSpam can score a message without one, so this example leaves the default empty list.

Implementing `ClassifierFeedbackInterface` lets your plugin report a wrong verdict. OOPSpam's report endpoint takes the same body plus `shouldBeSpam`, and the report appears on OOPSpam's dashboard. Feedback runs outside a check, so `logFeedbackError()` records any failure that would otherwise go unnoticed.

Register the class and use it in a plan in the same way as any classifier. OOPSpam works best with the sender's IP address and email, so pass both:

```php
use example\protection\OopSpam;
use Craft;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\Protect;
use verbb\protect\checks\Classification;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RequestSignals;
use verbb\protect\services\Classifiers;
use yii\base\Event;

Event::on(
    Classifiers::class,
    Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES,
    static function(RegisterComponentTypesEvent $event): void {
        $event->types[] = OopSpam::class;
    },
);

$plan = (new ProtectionPlan(handle: 'example-feedback'))
    ->add(new Classification([
        'provider' => new OopSpam(['apiKey' => '$OOPSPAM_API_KEY']),
    ]));

$result = Protect::getInstance()->check(new ProtectionContext(
    action: 'example.feedback.create',
    identity: new ProtectionIdentity(['email' => $email]),
    content: new ProtectionContent($message),
    request: RequestSignals::fromRequest(Craft::$app->getRequest()),
), $plan);
```

In this partial example, `$email` and `$message` are the visitor's validated input. A message OOPSpam scores at 3 or more gives a `SpamDetected` finding with the code `spam-detected` and metadata such as `['score' => 5]`, and your plugin decides whether to hold it for review or refuse it, as [Spam Classification](../feature-tour/spam-classification.md) describes. OOPSpam's docs say it treats localhost and private IP addresses as blocked by default, so a message sent from your own development machine may score higher than it would on a live site.

## Methods

::: reference
### `handle()`

**Returns:** `string`

Returns the classifier's handle, which identifies it in plans and logs. It must be lowercase, start with a letter and use only letters, digits and hyphens, and it should start with your plugin's handle so it can't clash with another plugin's. This method is static.
:::

::: reference
### `displayName()`

**Returns:** `string`

Returns the name of the classifier as people should see it, such as in a settings screen. This method is static.
:::

::: reference
### `classify()`

**Arguments:** `ProtectionContext $context`, the attempt being checked.

**Returns:** `ProviderResult`

Returns the classifier's verdict on the attempt's content. Use `Failed` for spam, `Passed` for clean content and `Error` when the classifier couldn't reach a conclusion.
:::

::: reference
### `requiredSignals()`

**Returns:** `string[]`

Returns the signals the classifier can't work without, such as `request.ipAddress`, `request.userAgent` or `identity.email`. When any of them is missing, the `Classification` check returns a `MissingRequiredSignal` error without calling `classify()`. The default is an empty array.
:::

::: reference
### `secretAttributes()`

**Returns:** `string[]`

Returns the names of the settings that hold credentials, such as an API key. These settings are left out of the plan's fingerprint. The default is an empty array.
:::

::: reference
### `feedback()`

**Arguments:** `ProtectionContext $context`, the attempt being reported, and `bool $spam`, whether it should have been treated as spam.

**Returns:** `ProviderResult`

Returns the outcome of reporting a wrong verdict to the service. Only implement it when the class implements `ClassifierFeedbackInterface`, and call `logFeedbackError()` with the result so a failure is recorded.
:::

::: reference
### `defineRules()`

**Returns:** `array`

Returns the validation rules for the classifier's settings. Merge your rules with the parent's, and a plan with invalid settings throws an `InvalidPlanException` when it's prepared or checked.
:::
