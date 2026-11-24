# Integrating an Action

This page shows the code a plugin needs to protect one action with Protect, from building the plan to responding to the result. The example protects feedback that visitors send about an article, with a honeypot, a minimum time, replay protection and a rate limit. It assumes your plugin already has a service, a controller action that receives the form and a template that renders it. [Protect a Feedback Form](../user-guides/protect-a-feedback-form.md) builds a similar plugin from an empty folder, with every file in full and a Turnstile challenge added at the end.

Protect examines each attempt and reports what it found, and does nothing else. Your plugin still decides who may post, validates what they wrote, protects the form with Craft's CSRF token, saves the feedback and tells the visitor what happened.

## Build the Plan

The form needs the same plan twice, once when it's prepared and once when it's checked, so build it in one place. A method on your plugin's protection service, such as `services/Protection.php`, works well. In the partial code on this page, `Example` stands for your plugin's main class, and `getProtection()` returns that service.

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;

public function getPlan(): ProtectionPlan
{
    return (new ProtectionPlan(handle: 'example-feedback'))
        ->add(new Honeypot())
        ->add(new MinimumTime(['seconds' => 3]))
        ->add(new ReplayProtection())
        ->add(new RateLimit(['rules' => [
            new RateLimitRule(['limit' => 5, 'window' => 60]),
        ]]));
}
```

This plan refuses a form with a filled honeypot, a form sent within three seconds of loading, a form that's already been sent, and more than five attempts a minute from one IP address. By default, the rate limit is counted separately for each action and site but shared across every article, so each visitor gets five a minute across every article on the site. Set `includeSubject` on the rule to give each article its own count, and see [Rate Limits](../feature-tour/rate-limits.md) for the other ways to divide it. Rate limits rely on Protect seeing each visitor's real address, so [configure your proxy](../get-started/installation-setup.md#configure-your-proxy-first) before relying on one.

## Prepare the Form

When your plugin renders the form, ask Protect to prepare it. Resolve and authorise the article first, then describe the attempt with a `ProtectionContext`. Its action names what the visitor is doing, namespaced with your plugin's handle, and its subject is the article, so a form prepared for one article can't be sent with another article's ID. In this partial controller code, `$article` is the entry your plugin has already resolved:

```php
use example\Example;
use verbb\protect\Protect;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\SubjectReference;

$preparation = Protect::getInstance()->prepare(new ProtectionContext(
    action: 'example.feedback.create',
    subject: SubjectReference::fromElement($article),
), Example::getInstance()->getProtection()->getPlan());
```

Pass `$preparation` to your template and output its inputs inside the form, next to Craft's CSRF input and your own fields:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('example/feedback/send') }}
    {{ preparation.renderHiddenInputs() }}

    <textarea name="message" required></textarea>
    <button type="submit">Send Feedback</button>
</form>
```

`renderHiddenInputs()` outputs a signed token and a honeypot field with a random name. The honeypot is hidden with the `hidden` attribute, so it needs no stylesheet, and this form works with no JavaScript at all. Prepare each protected form separately, even when two are on the same page, because each preparation can only be sent once.

A plan with a challenge or the JavaScript check needs Protect's browser code. In a form that posts normally, output the preparation with `renderBrowserInputs()` instead of `renderHiddenInputs()`, and Protect loads its bundled script, as [Server-Rendered Forms](browser-package.md#server-rendered-forms) describes. A form your own script submits mounts the npm package with the preparation's manifest instead, which is the public half of the preparation that tells the browser code what to set up, as [Your Own Script](browser-package.md#your-own-script) shows.

### Pages Served From a Cache

A preparation belongs to one visitor, so a page that holds one can't be stored in a cache other visitors share. If the article pages are cached, render the form without a preparation and have your script fetch one from an action of your own when the form appears. The response must never be cached either. Guests need to reach this action, so add `'prepare'` to the controller's `$allowAnonymous`, alongside the action that receives the form:

```php
use craft\elements\Entry;
use example\Example;
use verbb\protect\Protect;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\SubjectReference;
use yii\web\NotFoundHttpException;
use yii\web\Response;

public function actionPrepare(): Response
{
    // An entry query only finds live entries, so a disabled article or a draft can't be prepared.
    $article = Entry::find()
        ->section('articles')
        ->id((int)$this->request->getRequiredParam('entryId'))
        ->one();

    if (!$article) {
        throw new NotFoundHttpException('Article not found.');
    }

    $preparation = Protect::getInstance()->prepare(new ProtectionContext(
        action: 'example.feedback.create',
        subject: SubjectReference::fromElement($article),
    ), Example::getInstance()->getProtection()->getPlan());

    $this->response->setNoCacheHeaders();

    return $this->asJson([
        'manifest' => $preparation->browserManifest(),
        'csrfToken' => $this->request->getCsrfToken(),
    ]);
}
```

The script passes `manifest` to the browser package, and sends the CSRF token back in an `X-CSRF-Token` header, because a token written into a cached page would belong to whoever filled the cache. [Protect a Contact Form](../user-guides/protect-a-contact-form.md) builds a complete form this way.

## Check the Submission

When the form comes back, check it once, after your own permission checks and validation and immediately before saving. Validating first means a visitor who left the message empty hasn't used up their form or one of their rate-limit attempts. Don't run the check in element validation, a background resave or a database transaction that might be retried, because replay protection and rate limits record every check, so a second run for the same submission is refused as a replay.

This partial controller code uses the same plan and builds the context the same way as before, adding the request's signals for the rate limit and the submitted evidence. `$article` is the entry your plugin resolved from the submitted entry ID:

```php
use Craft;
use example\Example;
use verbb\protect\Protect;
use verbb\protect\errors\PreparationException;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;
use yii\web\HttpException;

$request = Craft::$app->getRequest();

try {
    $evidence = ProtectionEvidence::fromRequest($request);
} catch (PreparationException) {
    throw new HttpException(400, 'Refresh the page and try again.');
}

$result = Protect::getInstance()->check(new ProtectionContext(
    action: 'example.feedback.create',
    subject: SubjectReference::fromElement($article),
    request: RequestSignals::fromRequest($request),
    evidence: $evidence,
), Example::getInstance()->getProtection()->getPlan());

if (($retryAfter = $result->retryAfter()) !== null) {
    Craft::$app->getResponse()->getHeaders()->set('Retry-After', (string)$retryAfter);

    throw new HttpException(429, 'Please wait before sending more feedback.');
}

if ($result->hasErrors()) {
    throw new HttpException(503, 'Feedback is temporarily unavailable.');
}

if ($result->hasAdverseFindings()) {
    throw new HttpException(400, 'Refresh the page and try again.');
}

// Save the feedback your plugin has already validated.
```

`ProtectionEvidence::fromRequest()` reads the values the form submitted under `__protect`, from a normal form post or a JSON body. If they've been tampered with into the wrong shape, it throws a `PreparationException`, which this code turns into a 400 response. A request without them gives empty evidence, which this plan refuses with `preparation-invalid` because its checks read the preparation. `ProtectionEvidence::fromArray()` is the stricter alternative, which takes the decoded `__protect` value and throws the same exception when it's missing too.

The order of the branches matters. A rate limit comes first, because the visitor's message is fine and they only need to wait. Errors come next, meaning a check couldn't reach a conclusion, such as when the cache is down. This example refuses the feedback when that happens. Any other failure gets a deliberately general message, so a bot doesn't learn which check it tripped. For a native form, you'd usually send the visitor back to the form with a message and their text instead of throwing an exception, which also gives them a freshly prepared form.

Your plugin can choose to accept submissions while a provider is down instead, or to hold them for moderation, but never on a `ConfigurationError`, which means a secret key is missing or wrong and won't fix itself, or a `MissingRequiredSignal`, which means a rate limit or classifier couldn't run at all. If you add [spam classification](../feature-tour/spam-classification.md), look for `FindingType::SpamDetected` before the general rejection so you can hold flagged feedback for review. [Turning a Result into a Response](../feature-tour/results-and-findings.md#turning-a-result-into-a-response) shows a fuller version with each of these branches. Protect logs every error once, so you don't need to log them yourself.

### Validate Settings When They're Saved

If your plugin has settings for Protect, such as provider keys, validate them when the settings are saved, so a site owner finds out about a mistake on your settings page rather than from a visitor. Build the same checks and providers your plan uses and call `validate()` on each. When a plan fails validation as it runs, `prepare()` and `check()` throw an `InvalidPlanException`, whose `errors` property holds the failing component's errors, or an `InvalidComponentException` if the plan uses a class that isn't registered.

### Let Site Owners Choose a Provider

To let site owners pick a challenge provider or spam classifier in your plugin's settings, use `ProviderSettingsTrait` on your settings model. It adds a setting for the chosen challenge provider and spam classifier, the keys each built-in provider needs, and the rules that check them. Pass a callback to `defineProviderRules()` that says whether protection is on, so keys are only required while it is:

```php
use craft\base\Model;
use verbb\protect\base\ProviderSettingsTrait;

class Settings extends Model
{
    use ProviderSettingsTrait;

    public bool $enableProtection = true;

    protected function defineRules(): array
    {
        return [
            ...$this->defineProviderRules(fn() => $this->enableProtection),
        ];
    }
}
```

Your plan then builds the chosen providers from the settings. `createChallengeProvider()` takes the expected action and the hostnames a token may come from, and `createSpamClassifier()` takes the site URL Akismet reports against and Akismet's name for what's being checked:

```php
if ($settings->hasChallengeProvider()) {
    $plan->add(new Challenge(['provider' => $settings->createChallengeProvider('example_feedback', ['example.com'])]));
}

if ($classifier = $settings->createSpamClassifier($site->getBaseUrl(), 'contact-form')) {
    $plan->add(new Classification(['provider' => $classifier]));
}
```

Render the matching control panel fields with `renderProviderSettings()`, once for `challenge` and once for `classifier`. Pass your settings and your plugin's handle, which translates the labels and shows a warning on any setting your config file overrides. If your plugin masks saved secrets, pass what to show for each as `secrets`. Protect boots on demand rather than with Craft, so call it from your plugin's own code, such as a method on your protection service:

```php
use Twig\Markup;
use verbb\protect\Protect;

public function renderSettings(string $section, Settings $settings): Markup
{
    return Protect::getInstance()->renderProviderSettings($section, [
        'settings' => $settings,
        'pluginHandle' => 'example',
    ]);
}
```

Then call that method from your settings template, inside the same namespace as your other settings fields:

```twig
{{ craft.app.plugins.getPlugin('example').getProtection().renderSettings('challenge', settings) }}
```

Where the settings are stored and who may change them is up to your plugin. Every Verbb plugin that offers these settings uses the same names, so a site can point them all at the same environment variables.

## Retry Without Losing Content

Replay protection uses up a preparation before any provider is called, so a failed attempt can't be sent again, even when the failure was a provider outage or a save that went wrong afterwards. Keep the visitor's text, get a fresh preparation for the same action, and let them try again. Rate limits also count every attempt that reaches them, including ones that fail later.

Sometimes your plugin can't tell whether an attempt succeeded, such as when the connection drops after the feedback is saved but before the response arrives. If a retry mustn't create a second copy, your own code can create an idempotency key for the attempt, send it with every retry and pass it to the context as `idempotencyKey`. The same preparation with the same key then passes replay protection again, while a different key, or no key, is refused with `ReplayDetected`. Protect stores only a hash of the key. [Safe Retries with an Idempotency Key](../feature-tour/replay-protection.md#safe-retries-with-an-idempotency-key) explains it in full.

The key only gets the retry past replay protection, so your plugin has to provide the rest:

- Look up the key before anything with a side effect happens, and never let one key cause a second side effect. That includes side effects that create something first, such as a guest flow that creates a customer before the order.
- Enforce the key with an atomic constraint, such as a unique database column, because two parallel requests with the same key both pass replay protection.
- Don't take the `idempotent-retry` reason to mean the first attempt saved anything.
- Get a new challenge token for each retry, because tokens can only be verified once, as [Retain Input During a Retry](browser-package.md#retain-input-during-a-retry) describes.

## Test Your Integration

Before relying on the integration, check that a normal submission saves, then try each way one can be refused and confirm that nothing is saved. If your form sends visitors back with their text instead of throwing an exception, check that too.

- a honeypot filled in from the browser console
- a submission straight after the page loads
- a form left open past its `preparationTtl`
- the same request sent twice
- enough attempts to reach the rate limit
- the cache unavailable, which gives a `state-unavailable` error
