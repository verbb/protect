# Replay Protection

A signed preparation proves that your site issued a form, but on its own it doesn't stop that form being used again. A script can load your comment form once, wait out the minimum time, then post the same hidden inputs a thousand times, and every copy passes the honeypot and the minimum time. `ReplayProtection` makes each preparation single use, so every submission needs a form that was freshly prepared for it.

## Adding Replay Protection

`ReplayProtection` has no settings of its own. Add it to the same plan your template and controller already share:

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-comments'))
    ->add(new Honeypot())
    ->add(new MinimumTime(['seconds' => 3]))
    ->add(new ReplayProtection());
```

Nothing changes in the form. The preparation already carries a random nonce, and replay protection uses that nonce when the comment is posted.

## How a Claim Works

When `check()` reaches replay protection, Protect records a claim on the preparation's nonce. The first claim passes. Any later attempt with the same preparation fails with a `ReplayDetected` finding and the code `replay-detected`. The claim is kept until the preparation itself expires, after the plan's `preparationTtl` (an hour by default), and past that point the old form is rejected as expired anyway.

Replay protection runs in the stateful stage, after the passive checks and before any challenge or spam classification. That order matters for what counts as using a preparation. Once an attempt reaches replay protection, its preparation is spent, even if a later challenge fails, Akismet is unreachable, or your own save throws an exception. Under the default continuation strategy, an attempt that fails a passive check stops before the stateful stage and doesn't make a claim, but your code shouldn't rely on that. Treat every submission attempt as having used its preparation.

## Retrying After a Failed Attempt

Because a preparation can't be reused, a visitor who is turned away needs a fresh one before trying again, and they shouldn't lose what they wrote in the process.

For a native form, this usually takes care of itself. Your template calls `prepare()` every time it renders the comment form, so when your controller sends the visitor back to the form with their input, the form is prepared again. In Craft, that's the familiar pattern of setting route params and returning `null` from the action, so the same template renders with the submitted comment filled in.

For a form that posts with `fetch()`, keep the form's content where it is, fetch a new manifest from your own uncached preparation endpoint and mount it again. [Retain Input During a Retry](../developers/browser-package.md#retain-input-during-a-retry) covers this, including what to do while the new preparation is loading. Calling `reset()` on the browser controller clears challenge tokens but doesn't renew the preparation, so it isn't a substitute.

## Safe Retries with an Idempotency Key

Sometimes a visitor's connection drops after your plugin has saved their comment but before the response arrives. They click **Post Comment** again, and from their point of view it's the same attempt. If your plugin is built to recognise a repeated attempt and return the result of the first one, you can tell replay protection about it with an idempotency key.

An idempotency key is a value your own code creates for one submission attempt and sends with every retry of that attempt. Your frontend might generate one with `crypto.randomUUID()` when the visitor first submits and keep it until a submission succeeds or the content changes. Pass it to the context as `idempotencyKey`:

```php
use Craft;
use verbb\protect\Protect;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\RequestSignals;

// Partial controller code. $subject and $plan come from your protection service, and
// $evidence is built from the submitted __protect values with ProtectionEvidence::fromArray().
$request = Craft::$app->getRequest();
$key = $request->getBodyParam('idempotencyKey');

$result = Protect::getInstance()->check(new ProtectionContext(
    action: 'example.comments.create',
    subject: $subject,
    request: RequestSignals::fromRequest($request),
    evidence: $evidence,
    idempotencyKey: is_string($key) ? $key : null,
), $plan);
```

With a key, the same preparation can be presented again with the same key and still pass. Its result has the reason `idempotent-retry`, which you can read from `$result->resultFor(ReplayProtection::class)->reason`. The same preparation with a different key fails with `ReplayDetected`, and so does the same preparation without a key. An empty string counts as no key, which means strict single use, so reading a missing value from the request can't accidentally open up retries. Protect stores only a SHA-256 hash of the key, never the key itself.

The key lets the retry through replay protection. It doesn't make your save idempotent, and `idempotent-retry` isn't evidence that the first attempt saved anything. Your plugin still needs to look up the key before creating the comment, and to enforce it with an atomic constraint such as a unique database column, because two parallel requests with one key both pass replay protection. Retries also still count against [rate limits](rate-limits.md), and a [challenge](challenges.md) token can't be reused, so a retry needs a new token even though it keeps its preparation and key. [Retry Without Losing Content](../developers/integrating-an-action.md#retry-without-losing-content) lists the full set of rules your dedupe needs to follow.

## Where Claims Are Stored

Claims live in Protect's `State` component, which uses Craft's configured cache, guarded by Craft's configured mutex. Protect has no database tables, so the cache is the only record that a preparation has been used.

That has two consequences worth planning for. On a site with more than one web server, every server must see the same cache and the same mutex, otherwise a preparation used on one server is still unused on another. [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex) explains the setup. And if the cache is flushed, or evicts the claim under memory pressure, a used preparation becomes acceptable again until it expires. A shorter `preparationTtl` narrows that window, at the cost of forms that expire sooner, so a page that stays open for a long time should [refresh its preparation](../developers/browser-package.md#refresh-before-expiry).

```php
use verbb\protect\models\ProtectionPlan;

// Forms prepared for this plan expire after 20 minutes instead of an hour.
$plan = new ProtectionPlan(handle: 'example-comments', preparationTtl: 1200);
```

`preparationTtl` accepts 1 to 86400 seconds. Changing it changes the plan's fingerprint, so forms prepared under the old value stop being accepted.

If the cache can't be reached, replay protection returns an `Error` result with a `ProviderUnavailable` finding and the code `state-unavailable`, rather than guessing. Your plugin then applies its own availability policy, as [Results and Findings](results-and-findings.md) describes. To keep claims somewhere other than Craft's cache, replace the `State` component through module config, as [Replace the State Backend](../developers/extending-protect.md#replace-the-state-backend) shows. A replacement must keep every claim atomic across your whole deployment.
