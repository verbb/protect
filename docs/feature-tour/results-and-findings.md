# Results and Findings

Protect never decides what happens to a submission. `check()` returns a `ProtectionResult` describing what each check found, and your plugin turns that into a response, such as saving the comment, holding it for moderation, asking the visitor to wait or asking them to try again. This page explains what a result contains and how to read it without parsing messages.

## Check Results and Statuses

A result's `results` property holds one `CheckResult` for each check that ran, in the order they ran. Each has the check's class in `check`, a `status`, its `findings`, an optional `reason` and the time it took in `durationMs`.

The status is one of four `CheckStatus` cases. `Passed` means the check found nothing wrong, and `Failed` means it found something about the attempt, such as a filled honeypot or a spam verdict. `Error` means the check couldn't reach a conclusion, because a provider was down, a setting was wrong or a required signal was missing. `Skipped` means the check didn't apply to this attempt, either because its `condition` returned `false` or because it had nothing to examine, such as classification for an action with no content. Skipped results have the reason `not-applicable`.

The difference between `Failed` and `Error` is the most important thing in a result. A failure is evidence about the visitor. An error is evidence about your site or a provider, and a visitor shouldn't be told they look like a bot because Akismet had an outage.

Checks that were disabled, or that never ran because an earlier check stopped the run, have no entry in `results`. They appear only in the result's diagnostics, marked `disabled` or `bypassed`.

## Findings

A failed or errored check explains itself with one or more `Finding` objects. Each finding has a `type` from the `FindingType` enum, the `check` class that produced it, a machine-readable `code`, a short generic `summary`, and the `provider` class when a challenge or classifier provider was involved. Its `error` property is `true` for findings on an `Error` result. A finding's `metadata` only ever holds numbers from a short list of keys (`limit`, `window`, `retryAfter`, `score` and `threshold`), so a finding never carries submitted content, identities or a provider's raw response.

Each finding type covers one kind of outcome. The first five describe the attempt, and the last four describe a problem checking it:

| Finding Type | Codes | Produced By |
| --- | --- | --- |
| `PassiveCheckFailed` | `honeypot-populated`, `minimum-time`, `javascript-missing`, `preparation-invalid`, `preparation-expired`, `preparation-mismatch` | [Passive checks](passive-checks.md), and the preparation itself |
| `ChallengeFailed` | `challenge-token-missing`, `challenge-rejected`, `challenge-context-mismatch`, `challenge-score` | [Challenges](challenges.md) |
| `SpamDetected` | `spam-detected` | [Spam classification](spam-classification.md) |
| `RateLimitExceeded` | `rate-limit-exceeded` | [Rate limits](rate-limits.md) |
| `ReplayDetected` | `replay-detected` | [Replay protection](replay-protection.md) |
| `ProviderUnavailable` | `provider-unavailable`, `provider-http-error`, `state-unavailable` | Providers, and the cache behind replay protection and rate limits |
| `InvalidProviderResponse` | `invalid-provider-response` | Providers |
| `MissingRequiredSignal` | `rate-identity-missing`, `missing-required-signal` | Rate limits and classification |
| `ConfigurationError` | `provider-configuration` | Providers |

The `preparation-*` codes come from verifying the signed form, which happens when the first check that reads the preparation runs. They're reported as `PassiveCheckFailed`, unless a challenge is the first check to read the preparation, in which case they're `ChallengeFailed`. Either way, the visitor needs a fresh form.

The checks that read the preparation are the honeypot, minimum time, JavaScript presence, replay protection and challenges. A plan with none of them, such as one holding only a rate limit and classification, never verifies the preparation, so it doesn't notice an expired form or one prepared for a different action or subject. Include at least one of those checks in any plan whose forms you prepare.

## Asking Questions of a Result

`ProtectionResult` has methods for the questions your plugin usually needs to answer:

- `checksPassed()` is `true` only when there are no failures and no errors.
- `hasAdverseFindings()` is `true` when any check failed.
- `hasErrors()` is `true` when any check returned an error.
- `hasFinding()` takes a `FindingType` and says whether any finding has that type.
- `findingsOfType()` takes a `FindingType` and returns the matching findings.
- `findings()` returns every finding, and its `forCheck()` and `forProvider()` methods narrow it to one check or provider class.
- `resultFor()` takes a check class and returns its `CheckResult`, or `null` if it didn't run.
- `retryAfter()` returns the seconds until every exceeded rate limit has cleared, or `null` when no limit was exceeded.

For example, to read the reCAPTCHA score from a failed challenge, so you can tune `minimumScore`:

```php
use verbb\protect\enums\FindingType;

// $result is the ProtectionResult returned by check().
foreach ($result->findingsOfType(FindingType::ChallengeFailed) as $finding) {
    $score = $finding->metadata['score'] ?? null;
}
```

Collections of findings are countable and can be looped over like an array.

## Turning a Result into a Response

Each outcome calls for a different response, and the order you test them in matters. This partial controller code handles a guest comment after your plugin has validated it and run `check()`. It responds with a 429 for a rate limit, refuses to continue on a configuration problem or a missing signal, rejects any other failure, and holds a comment for moderation when Akismet flags it or a provider is down:

```php
use Craft;
use verbb\protect\enums\FindingType;
use yii\web\HttpException;

// $result is the ProtectionResult returned by check().
if (($retryAfter = $result->retryAfter()) !== null) {
    Craft::$app->getResponse()->getHeaders()->set('Retry-After', (string)$retryAfter);

    throw new HttpException(429, 'Please wait before posting another comment.');
}

if ($result->hasFinding(FindingType::ConfigurationError) || $result->hasFinding(FindingType::MissingRequiredSignal)) {
    throw new HttpException(503, 'Comments are temporarily unavailable.');
}

$spam = $result->hasFinding(FindingType::SpamDetected);

if ($result->hasAdverseFindings() && !$spam) {
    throw new HttpException(400, 'Refresh the page and try again.');
}

$holdForModeration = $spam || $result->hasErrors();

// Save the comment, using your plugin's pending status when $holdForModeration is true.
```

The rate limit comes first because it's the one outcome where the visitor needs to be told to wait, rather than that something went wrong. Configuration errors and missing signals come next, and are never treated as a reason to let a comment through. A `ConfigurationError` means a secret key is missing or wrong, which won't recover on its own and needs the site owner to fix it. A `MissingRequiredSignal` means a rate limit or classifier couldn't run at all, so letting the comment through would quietly switch that protection off.

Any other failure is rejected, with a message that doesn't tell a bot which check it tripped. Spam is the exception, because a moderator can review it. The remaining errors are provider and cache outages, plus `InvalidProviderResponse` when a provider's answer can't be read, and how to treat them is your plugin's availability policy. This example holds those comments for moderation, which keeps spam off the page without turning genuine visitors away. A stricter plugin would return a 503 instead, and a plugin with no moderation queue has to choose between rejecting and publishing. Make that choice deliberately, and don't publish on an error because it's the easiest branch to write.

The spam branch relies on the default continuation strategy, under which a spam verdict means every earlier check passed. For a native form, you'd usually send the visitor back to the form with a message and their content instead of throwing an exception, which also gives them a [fresh preparation](replay-protection.md#retrying-after-a-failed-attempt).

## Continuation Strategies

A plan's continuation strategy decides whether the run carries on after a check fails or errors. Checks run in stages, from passive checks to the cache-backed checks, then challenges, then classification, ordered by `priority` within a stage (lowest first) and by the order you added them after that. The `ContinuationStrategy` enum has three cases:

- `StopOnFailure` is the default. The run stops after a failure, but carries on after an error.
- `StopOnFailureOrError` stops after either.
- `RunAll` runs every check whatever happens.

The default suits most forms. A bot that fills in the honeypot stops the run before it can use the preparation, be counted by a rate limit or cost you an Akismet call. Continuing after an error means one provider being down doesn't stop the others from producing evidence, which your availability policy can use. `StopOnFailureOrError` suits a plugin that rejects on any error anyway, and would rather not spend a provider call it's going to ignore. `RunAll` collects every finding, which helps when you're investigating what a form is receiving, but it consumes the preparation and charges rate limits even for attempts that fail a passive check.

Set the strategy on the plan, or override it on a single check:

```php
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-feedback'))
    ->add(new Honeypot())
    ->add(new ReplayProtection(['continuation' => ContinuationStrategy::StopOnFailureOrError]))
    ->add(new Challenge(['provider' => new Turnstile([
        'siteKey' => '$TURNSTILE_SITE_KEY',
        'secretKey' => '$TURNSTILE_SECRET_KEY',
        'expectedAction' => 'feedback',
        'hostnames' => ['example.com'],
    ])]));
```

Here the plan keeps the default strategy, but if the cache behind replay protection is unavailable, the run stops before verifying the Turnstile token. A check's `continuation` decides what happens after that check, and the plan's strategy applies to every check without one. The strategy is part of the plan's fingerprint, so changing it invalidates forms prepared under the old one.

## Logging and Diagnostics

Protect logs every `Error` result once, as it happens, with the check's handle, the finding codes, the action and an execution ID. It writes these to its own `protect` log target, which on a default Craft install is a dated `protect` log file in `storage/logs`. The log never includes content, identities, IP addresses or provider responses. You don't need to log errors yourself to find out why a check couldn't run.

Each result has an `executionId`, the `action`, the `planFingerprint` and its total `durationMs`. If you store the execution ID alongside a held comment, a moderator's question about why it was held can be matched to Protect's log. A result can also be passed to `json_encode()`, which produces the action, execution ID, plan fingerprint, duration, each check's status and timing, and the findings with their codes and safe metadata. That's safe to write to your own logs. Keep submitted content, raw identities, preparations and challenge tokens out of any logging you add around it.

A plan that's misconfigured doesn't produce a result at all. `prepare()` and `check()` both validate the plan first, and throw an `InvalidPlanException` whose `errors` property lists the failing component's errors. Validate settings when they're saved, by constructing the same checks and providers and calling `validate()` on them, so a site owner finds out about a missing key from your settings page rather than from a visitor.
