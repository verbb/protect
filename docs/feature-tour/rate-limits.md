# Rate Limits

A rate limit caps how many attempts one visitor can make in a period of time. It's the check that stops a flood, such as the same contact message sent a hundred times in a minute or a script cycling through fresh forms to post comments as fast as it can. Every other check judges a single attempt, while a rate limit looks at how many attempts have come from the same place.

Protect's `RateLimit` check holds one or more `RateLimitRule` models. Each rule says how many attempts are allowed, over how long, and what the attempts are counted against.

## A First Rate Limit

Suppose your plugin accepts guest comments on articles, and nobody should need to post more than five in a minute. Add a rate limit to the plan in your plugin's protection service:

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;

$plan = (new ProtectionPlan(handle: 'example-comments'))
    ->add(new Honeypot())
    ->add(new ReplayProtection())
    ->add(new RateLimit(['rules' => [
        new RateLimitRule(['limit' => 5, 'window' => 60]),
    ]]));
```

By default, a rule counts attempts against the visitor's IP address, so the context you pass to `check()` needs the request's signals:

```php
use Craft;
use verbb\protect\models\RequestSignals;

$request = RequestSignals::fromRequest(Craft::$app->getRequest());
```

Pass it as `request` on the `ProtectionContext`, as [Integrating an Action](../developers/integrating-an-action.md) shows. The sixth comment from one address within the same minute fails with a `RateLimitExceeded` finding and the code `rate-limit-exceeded`.

## Rule Settings

Each rule is a model with these settings:

| Setting | Default | What It Controls |
| --- | --- | --- |
| `handle` | `default` | A name for the rule, unique within the check. Lowercase letters, digits and hyphens, starting with a letter. |
| `limit` | `5` | How many attempts are allowed in each window, from 1 to 86400. |
| `window` | `60` | The window's length in seconds, from 1 to 86400. |
| `dimensions` | `['request.ipAddress']` | What attempts are counted against. |
| `includeAction` | `true` | Whether each action has its own count. |
| `includeSubject` | `false` | Whether each subject, such as each article, has its own count. |
| `includeSite` | `true` | Whether each site has its own count. |

A rule with invalid settings, or two rules with the same handle, fails plan validation with an `InvalidPlanException`.

## Choosing What to Count Against

A rule's `dimensions` decide who an attempt belongs to. `request.ipAddress` is the visitor's IP address. `identity.<name>` reads a signal from the context's `ProtectionIdentity`, which can hold the built-in signals `userId`, `email`, `sessionId` and `clientId`, or your own namespaced signals such as `example.memberNumber`. A rule with more than one dimension counts each combination separately, so `['request.ipAddress', 'identity.userId']` gives every member a separate count on each address.

Identity signals must come from something your server already trusts, such as Craft's current user, never from a value the visitor submitted. If your plugin also accepts comments from signed-in members, a rule keyed on the member gives each account its own limit, however many addresses they post from:

```php
use Craft;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\RateLimitRule;

$rule = new RateLimitRule(['handle' => 'member', 'limit' => 10, 'window' => 600, 'dimensions' => ['identity.userId']]);

// When checking, pass the signed-in user's ID as the identity.
$identity = new ProtectionIdentity(['userId' => Craft::$app->getUser()->getId()]);
```

When a rule's dimension has no value, such as `identity.userId` for a guest, the check can't count the attempt. It returns an `Error` result with a `MissingRequiredSignal` finding and the code `rate-identity-missing`, and charges none of its rules. The same happens to `request.ipAddress` when the request has no valid client address. Use identity rules in plans for actions that require signing in, and build a separate plan for guests, rather than letting guest attempts fall through to an error.

A session ID is a weak identity for guests, because a script can discard its cookie and start a new session for every attempt. Pair it with an address rule rather than relying on it alone.

## Per-Article and Site-Wide Limits

The three `include` settings decide how finely the count is divided. With the defaults, a guest gets five comments a minute on each site, shared across every article. Setting `includeSubject` to `true` gives them five a minute on each article instead, which suits an action where posting on many subjects is normal, such as voting.

The site comes from the context's `SubjectReference`. A context without a subject, or a subject without a site ID, isn't divided by site, whatever `includeSite` says.

Setting `includeAction` to `false` lets several actions share one count, such as new comments and replies. The rule's own settings are part of what's counted, so the rule must be identical in each plan for them to share. For the same reason, changing a rule's limit, window or other settings starts its counts again from zero.

## Combining Rules

One rule can't express both "no bursts" and "not too many in an hour", but two rules can. Give each a handle:

```php
use verbb\protect\checks\RateLimit;
use verbb\protect\models\RateLimitRule;

$rateLimit = new RateLimit(['rules' => [
    new RateLimitRule(['handle' => 'burst', 'limit' => 5, 'window' => 60]),
    new RateLimitRule(['handle' => 'hourly', 'limit' => 20, 'window' => 3600]),
]]);
```

Every attempt is counted by every rule, and the check fails if any rule is over its limit. Protect resolves the dimensions for all the rules before it counts anything, so a rule with a missing signal never leaves another rule charged.

## Fixed Windows

Each window is a fixed slice of time aligned to the clock, so a 60-second window resets on each minute rather than 60 seconds after the visitor's first attempt. This keeps counting cheap, but it means a visitor can use a full allowance at the end of one window and another full allowance at the start of the next. With the burst rule above, that's up to ten comments in a few seconds around the boundary. If that matters, add a longer rule alongside it, like the hourly rule above.

Attempts over the limit are still counted, and every attempt that reaches the rate limit counts, including attempts that go on to fail a challenge and [idempotent retries](replay-protection.md#safe-retries-with-an-idempotency-key). The count resets when the window ends.

## Responding to a Limit

A visitor who has hit a limit should be told to wait, not that something is wrong with their comment. `retryAfter()` on the result gives the seconds until every exceeded rule's window ends, or `null` if no rule was exceeded, which suits an HTTP 429 response with a `Retry-After` header:

```php
use Craft;
use yii\web\HttpException;

// $result is the ProtectionResult returned by check().
if (($retryAfter = $result->retryAfter()) !== null) {
    Craft::$app->getResponse()->getHeaders()->set('Retry-After', (string)$retryAfter);

    throw new HttpException(429, 'Please wait before posting another comment.');
}
```

Each `RateLimitExceeded` finding also carries the rule's `limit`, `window` and `retryAfter` in its metadata. [Results and Findings](results-and-findings.md) shows how this fits with the rest of a result.

## IP Addresses, Proxies and IPv6

A rate limit keyed on IP addresses is only as good as the address Protect sees. Behind a CDN or load balancer, a site that hasn't told Craft about its proxies sees the proxy's address on every request, and all your visitors share one limit. [Configure Your Proxy First](../get-started/installation-setup.md#configure-your-proxy-first) explains how Protect resolves the address and what to set.

IPv4 addresses are counted individually. An IPv6 visitor usually controls a whole /64 block of addresses and can switch between them freely, so Protect counts every address in the same /64 together. The grouping applies only to rate limits, and Akismet and challenge providers still receive the exact address. Many people can share one IPv4 address too, such as an office, a school or a mobile network, so set address limits with room for that.

## Where Counts Are Stored

Counts live in Craft's cache, under keys derived with an HMAC, so the cache holds no raw IP addresses or emails. On a site with more than one web server, every server must share the cache and mutex, as [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex) describes. Flushing the cache resets every window. If the cache can't be reached, the check returns an `Error` result with the code `state-unavailable` rather than letting attempts through uncounted, and your plugin decides how to respond.
