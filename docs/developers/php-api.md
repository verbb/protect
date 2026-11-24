# PHP API

This page lists the classes your plugin works with when it uses Protect, along with their properties, methods and settings. It's a place to look things up once you know the shape of an integration. [Integrating an Action](integrating-an-action.md) walks through preparing and checking a form from start to finish, and the [Feature Tour](../feature-tour/how-protect-works.md) explains what each check does and when to use it.

Every class lives under the `verbb\protect` namespace. The examples on this page protect article feedback with the action `example.feedback.create` and a plan with the handle `example-feedback`.

## The Protect Module

`verbb\protect\Protect` is the entry point. It's a Yii module that registers itself the first time you call `getInstance()`, so your plugin needs no bootstrap code, and every plugin on the site shares the same instance.

::: reference
### `getInstance()`

**Returns:** `Protect`

Returns the shared instance, registering the module on first use.

```php
use verbb\protect\Protect;

$protect = Protect::getInstance();
```
:::

::: reference
### `prepare(context, plan)`

**Arguments:** `ProtectionContext`, `ProtectionPlan` · **Returns:** `ProtectionPreparation`

Validates the plan and prepares a form for one attempt. Call it each time the form is rendered, or from an uncached endpoint for a form whose page is cached. It throws an `InvalidPlanException` if the plan's settings are invalid or a challenge's site key resolves to nothing, and an `InvalidComponentException` if the plan uses a class that isn't registered.
:::

::: reference
### `check(context, plan)`

**Arguments:** `ProtectionContext`, `ProtectionPlan` · **Returns:** `ProtectionResult`

Validates the plan, runs its checks against the submitted evidence and returns the result. Call it once per attempt, after your own validation and before saving anything, because replay protection and rate limits record each attempt. It throws an `InvalidPlanException` for an invalid plan. Problems with the visitor's evidence or with a provider come back in the result rather than as exceptions.
:::

::: reference
### `stateKey(dimensions)`

**Argument:** `array` · **Returns:** `string`

Derives a cache key from an array of values using an HMAC of your site's security key, so the key never contains raw IP addresses or emails. Use it with `getState()` when your plugin keeps its own counters, as [Extending Protect](extending-protect.md#replace-the-state-backend) describes.

```php
// $userId is the signed-in user's ID.
$key = Protect::getInstance()->stateKey(['example-feedback-budget', $userId]);
```
:::

The module's services are available through `getChecks()`, `getChallenges()`, `getClassifiers()`, `getPreparations()` and `getState()`. Most plugins only need `getState()`, and only when they extend Protect.

## Plans

### `ProtectionPlan`

A plan is the list of checks for one form and their settings. Build it in one place, such as your plugin's protection service, so preparing and checking always use the same plan. Changing any part of a plan, apart from a check's `condition` closure, changes its fingerprint, and forms prepared under the old plan are refused once with `preparation-mismatch`.

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(
    handle: 'example-feedback',
    continuation: ContinuationStrategy::StopOnFailure,
    preparationTtl: 3600,
))
    ->add(new Honeypot())
    ->add(new MinimumTime(['seconds' => 3]));
```

::: reference
### `handle`

**Type:** `string|null` · **Default:** `null`

A name for the plan, which becomes part of its fingerprint. Give each form its own handle, and change it when you change a check's `condition`, because the closure's code isn't part of the fingerprint.
:::

::: reference
### `continuation`

**Type:** `ContinuationStrategy` · **Default:** `ContinuationStrategy::StopOnFailure`

Whether the run carries on after a check fails or errors. [Continuation Strategies](../feature-tour/results-and-findings.md#continuation-strategies) compares the three options.
:::

::: reference
### `preparationTtl`

**Type:** `int` · **Default:** `3600`

How many seconds a prepared form stays valid, from `1` to `86400`.
:::

::: reference
### `add(check)`

**Argument:** `Check` · **Returns:** `ProtectionPlan`

Adds a check and returns the plan, so calls can be chained. A plan holds each check class once, so adding a second `Challenge`, for example, throws an `InvalidPlanException`.
:::

::: reference
### `checks()`

**Returns:** `Check[]`

The checks in the order they were added. Protect runs them by stage and `priority`, not in this order.
:::

::: reference
### `validate()`

**Returns:** `void`

Throws an `InvalidPlanException` if `preparationTtl` is out of range or an enabled check's settings are invalid, and an `InvalidComponentException` if a check or provider isn't registered. `prepare()` and `check()` call it for you.
:::

::: reference
### `fingerprint()`

**Returns:** `string`

A hash of the plan's handle, strategy, lifetime and every check's public settings. Secret settings such as API keys are left out.
:::

### Checks

Each check is a component, so its settings go in the array passed to its constructor. Protect runs checks in four stages, cheapest first, and within a stage by `priority` and then the order they were added.

| Check | Stage | Its Own Settings | Explained In |
| --- | --- | --- | --- |
| `checks\Honeypot` | Passive | None | [Passive Checks](../feature-tour/passive-checks.md#honeypot) |
| `checks\MinimumTime` | Passive | `seconds`, from `0` to `300`, default `3` | [Passive Checks](../feature-tour/passive-checks.md#minimum-time) |
| `checks\JavaScriptPresence` | Passive | None | [Passive Checks](../feature-tour/passive-checks.md#javascript-presence) |
| `checks\ReplayProtection` | Stateful | None | [Replay Protection](../feature-tour/replay-protection.md) |
| `checks\RateLimit` | Stateful | `rules`, an array of `RateLimitRule` models | [Rate Limits](../feature-tour/rate-limits.md#rule-settings) |
| `checks\Challenge` | Challenge | `provider`, a `Turnstile`, `RecaptchaV3`, `Hcaptcha` or `FriendlyCaptcha` | [Challenges](../feature-tour/challenges.md) |
| `checks\Classification` | Classification | `provider`, an `Akismet`, `CleanTalk` or `OopSpam`, or your own classifier | [Spam Classification](../feature-tour/spam-classification.md) |

Every check also accepts these settings:

::: reference
### `enabled`

**Type:** `bool` · **Default:** `true`

Turns the check off without removing it from the plan. A disabled check doesn't run or prepare anything, and appears in the result's diagnostics as `disabled`.
:::

::: reference
### `priority`

**Type:** `int` · **Default:** `0`

Orders checks within the same stage. Lower values run first, and checks with the same priority run in the order they were added.
:::

::: reference
### `condition`

**Type:** `Closure(ProtectionContext): bool|null` · **Default:** `null`

Receives the context and returns `false` to skip the check for that attempt, which records it as `Skipped` with the reason `not-applicable`. The closure's code isn't part of the plan's fingerprint, so give the plan a new handle when you change it.

```php
use verbb\protect\checks\Classification;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\models\ProtectionContext;

// Only classify feedback from guests.
$classification = new Classification([
    'provider' => new Akismet(['apiKey' => '$AKISMET_API_KEY', 'blogUrl' => '$PRIMARY_SITE_URL']),
    'condition' => fn(ProtectionContext $context) => $context->identity?->get('userId') === null,
]);
```
:::

::: reference
### `continuation`

**Type:** `ContinuationStrategy|null` · **Default:** `null`

Overrides the plan's continuation strategy for what happens after this check.
:::

A rate limit's rules are `models\RateLimitRule` models, with the settings `handle`, `limit`, `window`, `dimensions`, `includeAction`, `includeSubject` and `includeSite`. Every rule in one check needs a different `handle`. [Rule Settings](../feature-tour/rate-limits.md#rule-settings) lists their defaults and allowed values.

### Providers

Challenge providers share the same settings. They resolve environment expressions such as `$TURNSTILE_SECRET_KEY` when they're used, and keep `secretKey` out of the plan's fingerprint, the browser manifest and results.

| Setting | Type | Default | Notes |
| --- | --- | --- | --- |
| `siteKey` | `string` | None | Required. Public, and sent to the browser in the manifest. |
| `secretKey` | `string` | None | Required. Only used on the server. |
| `expectedAction` | `string` | None | Required for Turnstile, which accepts up to 32 letters, digits, underscores and hyphens, and reCAPTCHA, which accepts letters, digits, underscores and slashes. hCaptcha and Friendly Captcha don't use it. |
| `hostnames` | `string[]` | None | Required. Every hostname the form is served on. |
| `sendIp` | `bool` | `false` | Sends the visitor's IP address to the provider as `remoteip`. Friendly Captcha doesn't take one, so it has no effect there. |

`integrations\challenges\Turnstile` has no other settings. `integrations\challenges\RecaptchaV3` adds `minimumScore`, a number from `0` to `1` that defaults to `0.5`. `integrations\challenges\Hcaptcha` adds `maximumScore`, a number from `0` to `1` or `null` (the default), which fails an hCaptcha Enterprise token whose risk score is above it. `integrations\challenges\FriendlyCaptcha` adds `endpoint`, either `global` (the default) or `eu`, and takes its API key as `secretKey`. [Challenges](../feature-tour/challenges.md) explains how verification works and what each failure code means.

`integrations\classifiers\Akismet` takes these settings, and keeps `apiKey` out of the fingerprint:

| Setting | Type | Default | Notes |
| --- | --- | --- | --- |
| `apiKey` | `string` | None | Required. Accepts an environment expression. |
| `blogUrl` | `string` | None | Required. The front page of the site. Accepts an environment expression. |
| `contentType` | `string` | `comment` | Akismet's name for the kind of content, such as `contact-form` or `forum-post`. |
| `blogLanguage` | `string|null` | `null` | A language code such as `en`. Defaults to the first two letters of Craft's application language. |
| `testMode` | `bool` | `false` | Marks every request as a test. |

Akismet requires the context to carry the visitor's IP address. It also implements `ClassifierFeedbackInterface`, whose `feedback(context, spam)` method reports a wrong verdict and returns a `ProviderResult`. [Spam Classification](../feature-tour/spam-classification.md) covers both.

`integrations\classifiers\CleanTalk` takes only `apiKey`, CleanTalk's access key, which accepts an environment expression and stays out of the fingerprint. It doesn't implement `ClassifierFeedbackInterface`.

`integrations\classifiers\OopSpam` takes these settings, and keeps `apiKey` out of the fingerprint:

| Setting | Type | Default | Notes |
| --- | --- | --- | --- |
| `apiKey` | `string` | None | Required. Accepts an environment expression. |
| `spamThreshold` | `int` | `3` | The score from `1` to `6` at which a message counts as spam. |
| `checkForLength` | `bool` | `false` | Scores a message of 20 characters or fewer as spam. |
| `allowedLanguages` | `string[]` | `[]` | Two-letter language codes. |
| `allowedCountries` | `string[]` | `[]` | Two-letter country codes. Requires the visitor's IP address. |
| `blockedCountries` | `string[]` | `[]` | Two-letter country codes. Requires the visitor's IP address. |
| `blockTempEmail` | `bool` | `false` | Treats a disposable email address as spam. |
| `blockVPN` | `bool` | `false` | Treats VPN, proxy and Tor addresses as spam. |
| `blockDC` | `bool` | `false` | Treats data centre addresses as spam. |
| `urlFriendly` | `bool` | `false` | Reduces how much links raise the score. |
| `logIt` | `bool` | `false` | Keeps a log of each request in the OOPSpam dashboard. |
| `source` | `string` | None | Required when `logIt` is on. A name for your site in that log. |

OOPSpam implements `ClassifierFeedbackInterface` too, and sends corrections to its report endpoint.

## Describing an Attempt

### `ProtectionContext`

A context describes one attempt to Protect, both when the form is prepared and when it's checked. The values that bind a preparation (the action and subject) must be the same both times. Every argument except `action` is optional, so a context carries only what the plan's checks need. A context doesn't read the current request, so you can build one in a controller, a queue job or a console command.

```php
use Craft;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

// In a controller action. $article is the entry your plugin has already resolved.
$request = Craft::$app->getRequest();

$context = new ProtectionContext(
    action: 'example.feedback.create',
    subject: SubjectReference::fromElement($article),
    request: RequestSignals::fromRequest($request),
    evidence: ProtectionEvidence::fromRequest($request),
);
```

::: reference
### `action`

**Type:** `string`

A stable name for what the visitor is attempting, up to 200 characters. Namespace it with your plugin's handle, such as `example.feedback.create`, so a form prepared for one action can't be submitted to another. An empty action throws an `InvalidArgumentException`.
:::

::: reference
### `identity`

**Type:** `ProtectionIdentity|null` · **Default:** `null`

Signals about who is attempting the action, which rate limits can count against and classifiers can send to their service.
:::

::: reference
### `subject`

**Type:** `SubjectReference|null` · **Default:** `null`

What the attempt is about, such as the article a comment is posted on. It's part of what a preparation is bound to, and its site divides rate limits by site.
:::

::: reference
### `content`

**Type:** `ProtectionContent|null` · **Default:** `null`

The submitted text, for classification. Without content, classification is skipped.
:::

::: reference
### `request`

**Type:** `RequestSignals|null` · **Default:** `null`

The visitor's IP address and, when you ask for them, user agent and referrer.
:::

::: reference
### `evidence`

**Type:** `ProtectionEvidence|null` · **Default:** `null`

The values the form submitted under `__protect`. Leave it out when preparing.
:::

::: reference
### `metadata`

**Type:** `array` · **Default:** `[]`

Scalar values for your own checks. Built-in checks ignore it. A value that isn't scalar or `null` throws an `InvalidArgumentException`.
:::

::: reference
### `idempotencyKey`

**Type:** `string|null` · **Default:** `null`

A key your own code creates for one submission attempt, which lets replay protection accept a retry of that attempt. An empty string counts as no key. [Safe Retries with an Idempotency Key](../feature-tour/replay-protection.md#safe-retries-with-an-idempotency-key) explains when to use one.
:::

::: reference
### `prepared(key)`

**Argument:** `string` · **Returns:** `mixed`

Inside a check, reads a value from the verified preparation, such as `nonce`, `issuedAt`, `expiresAt` or `honeypot`. It returns `null` until a check that reads the preparation has run.
:::

::: reference
### `signal(name)`

**Argument:** `string` · **Returns:** `string|int|float|bool|null`

Reads `request.ipAddress`, `request.userAgent` or `identity.<name>`, the same names rate-limit dimensions and a classifier's `requiredSignals()` use.
:::

### `ProtectionIdentity`

Takes an array of signals. The built-in keys are the `IdentitySignalType` values `userId`, `email`, `sessionId` and `clientId`. Your own signals use a `<plugin>.<name>` key, such as `example.memberNumber`, and a rate rule reads them as `identity.example.memberNumber`. Values must be scalar, and `null` values are dropped. Read a signal with `get()`, which accepts a key or an `IdentitySignalType` case.

```php
use Craft;
use verbb\protect\models\ProtectionIdentity;

$identity = new ProtectionIdentity(['userId' => Craft::$app->getUser()->getId()]);
```

Only use values your server already trusts for rate limits, such as Craft's current user. A value the visitor typed, such as an email address, is fine to describe content to a classifier, but a visitor can change it on every attempt.

### `SubjectReference`

Identifies what an attempt is about, with a `type`, an `id`, an optional `siteId` and an optional `url`. `SubjectReference::fromElement($element)` builds one from any element, including its URL, which Akismet receives as the page the content was posted on. The type, ID and site are part of what a preparation is bound to, so build the subject the same way when preparing and checking.

```php
use verbb\protect\models\SubjectReference;

$subject = SubjectReference::fromElement($article);
```

### `ProtectionContent`

Describes submitted content for classification. Its constructor takes `text`, `authorName`, `authorUrl`, `dateCreated`, `edited` and `fields`, all optional. Pass `dateCreated` with the original date for anything other than a brand new submission, and set `edited` to `true` when existing content has changed. `fields` carries extra scalar values for your own classifiers, and Akismet doesn't send them.

```php
use verbb\protect\models\ProtectionContent;

// $message and $name are the visitor's input, already validated as strings.
$content = new ProtectionContent(text: $message, authorName: $name);
```

### `RequestSignals`

Holds `ipAddress`, `userAgent` and `referrer`. `RequestSignals::fromRequest($request)` collects the visitor's IP address, resolved as [Configure Your Proxy First](../get-started/installation-setup.md#configure-your-proxy-first) describes, and collects the user agent and referrer only when you pass `userAgent: true` and `referrer: true`. A console request gives empty signals. To store signals with a submission and use them later, such as for Akismet feedback, pass them to the constructor directly.

```php
use Craft;
use verbb\protect\models\RequestSignals;

$signals = RequestSignals::fromRequest(Craft::$app->getRequest(), userAgent: true, referrer: true);
```

`helpers\Ip::clientIp($request)` returns the same address, for anything else your plugin stores or compares.

### `ProtectionEvidence`

The values a form submits under `__protect`. Build it with one of two static methods.

::: reference
### `fromRequest(request)`

**Argument:** `craft\web\Request` · **Returns:** `ProtectionEvidence`

Reads `__protect` from the request body. A request without it gives empty evidence, which the check refuses with `preparation-invalid` when the plan has a check that reads the preparation. Values in the wrong shape throw a `PreparationException`, which you can catch and turn into a 400 response.
:::

::: reference
### `fromArray(data)`

**Argument:** `mixed` · **Returns:** `ProtectionEvidence`

Builds evidence from a decoded JSON value, such as the `__protect` object a script sends. Anything that isn't an array of well-formed evidence, including `null`, throws a `PreparationException`.
:::

## Preparing a Form

### `ProtectionPreparation`

`prepare()` returns a preparation, which holds the signed token for one attempt and the public details the browser needs.

::: reference
### `action`

**Type:** `string`

The action the form was prepared for.
:::

::: reference
### `renderHiddenInputs()`

**Returns:** `Twig\Markup`

Outputs Protect's hidden inputs and, when the plan has a honeypot, the honeypot field in a wrapper with the `hidden` attribute. Use it in a form that posts normally and doesn't need JavaScript, or in a form your own script submits, where the npm package mounts onto these inputs.

```twig
{{ preparation.renderHiddenInputs() }}
```
:::

::: reference
### `renderBrowserInputs(nonce)`

**Argument:** `string|null` · **Returns:** `Twig\Markup`

Outputs the same inputs plus a hidden element holding the form's manifest, and registers Protect's bundled script, which mounts on the form. Use it for a form that posts normally but has a challenge or the JavaScript check. Pass your page's CSP nonce if it has one. [Server-Rendered Forms](browser-package.md#server-rendered-forms) explains what the script does.
:::

::: reference
### `hiddenInputs()`

**Returns:** `array`

The hidden input names and values as an array, for rendering them yourself.
:::

::: reference
### `browserManifest()`

**Returns:** `BrowserManifest`

The public half of the preparation, which the browser package reads. It holds `instanceId`, `preparation`, `issuedAt`, `expiresAt`, `notBefore`, `honeypot`, `javascript` and `challenges`, and never a secret key. Times are Unix timestamps in seconds.
:::

A preparation can also be passed to `json_encode()` or a controller's `asJson()`, which gives `version`, `hiddenInputs`, `manifest` and `expiresAt`. The browser package only needs the manifest, so a preparation endpoint can return this, or its own object with the manifest under `manifest`, as [Pages Served From a Cache](integrating-an-action.md#pages-served-from-a-cache) does. Either way, send it with no-cache headers, because a preparation belongs to one visitor.

## Reading a Result

### `ProtectionResult`

`check()` returns a result. [Results and Findings](../feature-tour/results-and-findings.md) explains how to turn one into a response.

::: reference
### `results`

**Type:** `CheckResult[]`

One result for each check that ran, in the order they ran.
:::

::: reference
### `executionId`

**Type:** `string`

A random ID for this run, which also appears in Protect's log for any error.
:::

::: reference
### `action`, `planFingerprint` and `durationMs`

**Types:** `string`, `string`, `float`

The context's action, the plan's fingerprint and the run's total time in milliseconds.
:::

::: reference
### `diagnostics`

**Type:** `array`

Every check in the plan with its handle, status and duration, including checks that were `disabled` or `bypassed` because an earlier check stopped the run.
:::

::: reference
### `checksPassed()`

**Returns:** `bool`

`true` when no check failed and none returned an error.
:::

::: reference
### `hasAdverseFindings()`

**Returns:** `bool`

`true` when any check failed.
:::

::: reference
### `hasErrors()`

**Returns:** `bool`

`true` when any check couldn't reach a conclusion.
:::

::: reference
### `hasFinding(type)`

**Argument:** `FindingType` · **Returns:** `bool`

Whether any finding has the given type.
:::

::: reference
### `findingsOfType(type)`

**Argument:** `FindingType` · **Returns:** `FindingCollection`

The findings with the given type.
:::

::: reference
### `findings()`

**Returns:** `FindingCollection`

Every finding from every check.
:::

::: reference
### `resultFor(check)`

**Argument:** a check class name · **Returns:** `CheckResult|null`

The result for one check, or `null` if it didn't run.

```php
use verbb\protect\checks\ReplayProtection;

$reason = $result->resultFor(ReplayProtection::class)?->reason;
```
:::

::: reference
### `retryAfter()`

**Returns:** `int|null`

The seconds until every exceeded rate limit has cleared, or `null` if no limit was exceeded. Use it for a 429 response and its `Retry-After` header.
:::

A result can be passed to `json_encode()`, which gives the action, execution ID, plan fingerprint, duration, diagnostics and findings, and nothing the visitor submitted.

### `CheckResult`

Each check's result has these properties:

| Property | Type | Description |
| --- | --- | --- |
| `check` | `string` | The check's class name. |
| `status` | `CheckStatus` | `Passed`, `Failed`, `Skipped` or `Error`. |
| `findings` | `FindingCollection` | Empty for a passed or skipped check. |
| `reason` | `string|null` | Why the check passed or was skipped, such as `not-applicable` or `idempotent-retry`, when it gives one. |
| `durationMs` | `float` | How long the check took. |

### `Finding`

A finding has these properties. [Findings](../feature-tour/results-and-findings.md#findings) lists every type and code.

| Property | Type | Description |
| --- | --- | --- |
| `type` | `FindingType` | The kind of outcome. |
| `check` | `string` | The class of the check that produced it. |
| `code` | `string` | A machine-readable code, such as `minimum-time`. |
| `summary` | `string` | A short, generic description that's safe to log. |
| `provider` | `string|null` | The provider's class name, when a challenge or classifier provider was involved. |
| `error` | `bool` | `true` for a finding on an `Error` result. |
| `metadata` | `array` | Only numeric `limit`, `window`, `retryAfter`, `score` and `threshold` values. Anything else is dropped. |

### `FindingCollection`

A list of findings that you can count and loop over. `ofType()`, `forCheck()` and `forProvider()` each return a narrower collection.

```php
use verbb\protect\integrations\classifiers\Akismet;

$akismetFindings = $result->findings()->forProvider(Akismet::class);
```

## Enums

All enums live in `verbb\protect\enums`.

| Enum | Cases |
| --- | --- |
| `CheckStatus` | `Passed`, `Failed`, `Skipped`, `Error` |
| `FindingType` | `PassiveCheckFailed`, `ChallengeFailed`, `SpamDetected`, `RateLimitExceeded`, `ReplayDetected`, `ProviderUnavailable`, `InvalidProviderResponse`, `MissingRequiredSignal`, `ConfigurationError` |
| `ContinuationStrategy` | `StopOnFailure`, `StopOnFailureOrError`, `RunAll` |
| `ExecutionStage` | `Passive`, `Stateful`, `Challenge`, `Classification` |
| `IdentitySignalType` | `UserId`, `Email`, `SessionId`, `ClientId` |

## Exceptions

All exceptions live in `verbb\protect\errors` and extend `ProtectException`. They describe mistakes in your code or configuration. Anything about the visitor, or a provider being down, comes back in the result instead.

| Exception | Thrown When |
| --- | --- |
| `InvalidPlanException` | A plan, check or provider has invalid settings, a check class is added twice, or a challenge's site key is empty. Its `errors` property holds the failing component's errors. |
| `PreparationException` | `ProtectionEvidence::fromRequest()` or `fromArray()` receives values in the wrong shape. |
| `InvalidComponentException` | A check or provider class isn't registered, doesn't extend the right base class, or has an invalid handle. |
| `DuplicateRegistrationException` | Two different classes are registered with the same handle. |
| `StateException` | The cache or mutex behind replay protection and rate limits fails. Protect catches it around every check, including your own, and returns a `state-unavailable` error, so you only see it when you call `getState()` outside a check. |

## Logging

Protect logs every `Error` result as it happens, with the check's handle, its finding codes, the action and the execution ID, to its own `protect` log target. On a default Craft install that's a dated `protect` file in `storage/logs`. Akismet and CleanTalk add a line of their own with the explanation they received when they report a configuration error, and classifier feedback failures are logged too. You don't need to log errors yourself.
