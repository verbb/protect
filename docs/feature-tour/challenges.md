# Challenges

A challenge asks a third-party provider to judge whether the visitor's browser is being driven by a person. The provider's script runs in the browser, examines the visit, and issues a token. Your server then sends that token to the provider, which confirms whether it's genuine and what it was issued for. Challenges catch automated browsers that get past the [passive checks](passive-checks.md), at the cost of a provider account, a script from the provider's domain, and a network call when each form is checked.

Protect supports Cloudflare Turnstile, Google reCAPTCHA v3, hCaptcha and Friendly Captcha. All four run in the browser, so a form with a challenge needs Protect's [browser code](../developers/browser-package.md) mounted on it. A Twig form gets that from `renderBrowserInputs()`, which loads the script bundled with Protect, and a form your own script submits uses the `@verbb/protect` npm package.

## Choosing a Provider

Turnstile is usually invisible. Most visitors never see it, and Cloudflare shows an interactive challenge only when it isn't confident about a visit. reCAPTCHA v3 is never interactive. Google returns a score from 0 to 1 for each visit instead, and Protect compares it with a threshold you choose. A visitor that reCAPTCHA isn't sure about is rejected outright, while Turnstile gives them a chance to prove themselves.

hCaptcha works like Turnstile in Protect. It stays invisible until a visitor submits, and shows a puzzle only when hCaptcha wants more proof. Friendly Captcha never asks the visitor to solve anything. Its widget is a small visible box on the form that works through a proof-of-work puzzle in the browser while the visitor fills in the form, and Friendly Captcha offers EU hosting for sites that need their verification data to stay in the EU.

Choose whichever fits your audience, your privacy requirements and your existing accounts. All four are configured in much the same way, and the differences are covered under each provider below.

## Adding Turnstile

Create a Turnstile widget in the Cloudflare dashboard for the hostnames your form is served on, and keep its site key and secret key in environment variables. Then add a `Challenge` check with a `Turnstile` provider to the plan in your plugin's protection service. This example protects an article feedback form:

```php
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-feedback'))
    ->add(new Honeypot())
    ->add(new ReplayProtection())
    ->add(new Challenge(['provider' => new Turnstile([
        'siteKey' => '$TURNSTILE_SITE_KEY',
        'secretKey' => '$TURNSTILE_SECRET_KEY',
        'expectedAction' => 'feedback',
        'hostnames' => ['example.com', 'www.example.com'],
    ])]));
```

Every provider takes these settings. The `siteKey` is public, and Protect resolves it when the form is prepared and puts it in the browser manifest. The `secretKey` stays on the server and never appears in the manifest, the plan's fingerprint or a result. Both accept environment expressions such as `$TURNSTILE_SITE_KEY`. If the site key resolves to nothing, `prepare()` throws an `InvalidPlanException`, so a missing variable shows up when the form renders rather than as a broken widget.

`expectedAction` is a short name for what the form does. The browser package passes it to the provider when the challenge runs, and Protect rejects a token issued for any other action, so a token taken from your newsletter form can't be used on your feedback form. Turnstile accepts up to 32 letters, digits, underscores and hyphens. hCaptcha and Friendly Captcha don't have actions, so they don't use this setting.

`hostnames` lists every hostname the form is served on, and it's required. The provider reports which hostname the token was issued on, and Protect rejects a token from anywhere else, regardless of case. This stops someone solving your challenge on a page of their own and then submitting the token to your site.

`sendIp` defaults to `false`. Set it to `true` to send the visitor's IP address to the provider with the token, which gives the provider one more signal. It uses the same address as your rate limits, so [configure your proxy](../get-started/installation-setup.md#configure-your-proxy-first) first.

## How Turnstile Runs in the Browser

The browser code renders Turnstile without running it. The challenge only runs when the visitor submits, either when the bundled script holds the submission or when your own script calls `prepare()` immediately before sending, so a form that nobody posts never runs a challenge, and the token can't age out while the visitor is writing. If Cloudflare decides it needs an interactive challenge, the widget appears at that point, after the visitor has clicked submit. Keep the form visible and the visitor's text in place while `prepare()` is pending, so they can see and complete it.

Each token can be verified only once, so every submission attempt gets a new one, including a retry. After an error Cloudflare treats as permanent, such as a site key that doesn't match the hostname, `prepare()` rejects immediately until you call `reset()`. [How Turnstile Renders](../developers/browser-package.md#how-turnstile-renders) covers the details.

## Adding reCAPTCHA v3

Register a reCAPTCHA v3 key with Google for your hostnames, then use the `RecaptchaV3` provider in the same way:

```php
use verbb\protect\checks\Challenge;
use verbb\protect\integrations\challenges\RecaptchaV3;

$challenge = new Challenge(['provider' => new RecaptchaV3([
    'siteKey' => '$RECAPTCHA_SITE_KEY',
    'secretKey' => '$RECAPTCHA_SECRET_KEY',
    'expectedAction' => 'feedback',
    'hostnames' => ['example.com', 'www.example.com'],
    'minimumScore' => 0.7,
])]);
```

`minimumScore` is a number from 0 to 1 and defaults to `0.5`. A token whose score is below it fails with the code `challenge-score`, and the finding's metadata records the `score` and the `threshold`, which helps you tune the value from real traffic. reCAPTCHA's `expectedAction` accepts letters, digits, underscores and slashes.

reCAPTCHA v3 keys only work with the v3 API. If a v2 key is used by mistake, Google's response has no score or action, and Protect reports it as a configuration error rather than a failed visitor.

## Adding hCaptcha

Add a site in the hCaptcha dashboard for your hostnames, and keep its site key and your account's secret key in environment variables. Then use the `Hcaptcha` provider. It takes no `expectedAction`:

```php
use verbb\protect\checks\Challenge;
use verbb\protect\integrations\challenges\Hcaptcha;

$challenge = new Challenge(['provider' => new Hcaptcha([
    'siteKey' => '$HCAPTCHA_SITE_KEY',
    'secretKey' => '$HCAPTCHA_SECRET_KEY',
    'hostnames' => ['example.com', 'www.example.com'],
])]);
```

Without actions, an hCaptcha token is tied to your form by its site key and hostname. Protect sends the site key with every verification, so hCaptcha refuses a token issued for any other site key, and it checks the hostname against `hostnames` in the same way as the other providers. When hCaptcha is under heavy load, it reports the hostname as `not-provided` instead of the real one. Protect fails the challenge then, as it does for any hostname it can't confirm, so a visitor may need to try again while hCaptcha is busy.

`maximumScore` is for hCaptcha Enterprise accounts, whose responses include a risk score from 0 to 1, where a higher score means the visit looks more like a bot. It defaults to `null`, which ignores the score. When you set it, a token whose score is above it fails with the code `challenge-score`, and the finding's metadata records the `score` and the `threshold`. Setting it on an account that doesn't get scores is reported as a configuration error, because hCaptcha's response has no score to compare.

The browser code renders hCaptcha invisibly and runs it when the visitor submits, like Turnstile. If hCaptcha wants more proof, its puzzle opens over the page at that point, and closing it rejects `prepare()` with the code `challenge-closed` on the `protect:error` event.

## Adding Friendly Captcha

Create a site key in the Friendly Captcha dashboard for your hostnames, and an API key, then keep both in environment variables. Protect uses version 2 of Friendly Captcha's widget and API, which is what new accounts get. The API key goes in `secretKey`:

```php
use verbb\protect\checks\Challenge;
use verbb\protect\integrations\challenges\FriendlyCaptcha;

$challenge = new Challenge(['provider' => new FriendlyCaptcha([
    'siteKey' => '$FRIENDLY_CAPTCHA_SITE_KEY',
    'secretKey' => '$FRIENDLY_CAPTCHA_API_KEY',
    'hostnames' => ['example.com', 'www.example.com'],
    'endpoint' => 'eu',
])]);
```

`endpoint` chooses where the widget gets its puzzle and where Protect verifies the response. It defaults to `global`, and `eu` uses Friendly Captcha's EU-hosted service, which needs a plan that includes it. Protect puts the endpoint in the browser manifest, so the widget and the server always use the same one.

Friendly Captcha reports the origin each puzzle was solved on, and Protect checks its hostname against `hostnames`. When Friendly Captcha doesn't know the origin, it leaves it empty, and Protect fails the challenge, as it does for any origin it can't confirm. Friendly Captcha doesn't use the visitor's IP address, so `sendIp` has no effect.

The widget is a visible box that Protect adds at the end of the form. It starts its puzzle the first time the visitor focuses a field in the form, so the puzzle is usually finished by the time they submit, and a form nobody touches costs nothing. If the visitor submits before it's finished, `prepare()` waits for it. Each response can be verified only once, so a second attempt starts a new puzzle.

## One Challenge per Plan

A plan holds each check class once, so it can have one `Challenge` and therefore one provider. Adding a second throws an `InvalidPlanException`. Different forms can use different providers by using different plans.

## What Protect Verifies

When the attempt is checked, Protect sends the token and secret key to the provider. A token passes only if the provider says it's valid, its action matches `expectedAction` and its hostname is in `hostnames`. hCaptcha and Friendly Captcha have no action, so their tokens are checked against the site key and hostname. The provider enforces how long a token lasts and that it's used only once, so Protect doesn't keep a separate time limit that could reject a visitor whose clock is wrong. A successful verification is never cached.

Requests to the provider have short timeouts and don't follow redirects. If Turnstile can't be reached, Protect retries once with the same idempotency key, which Cloudflare treats as the same verification, so the single-use token survives a brief network problem. In the worst case, a Turnstile outage holds the request for about 8 seconds.

Challenges run after the passive and stateful checks, so by the time a token is verified, the attempt has already used its preparation if the plan has [replay protection](replay-protection.md), and been counted by any [rate limit](rate-limits.md).

## Failures, Outages and Configuration Errors

A challenge can go wrong in three different ways, and each deserves a different response. A failure means the visitor didn't pass, and Protect reports it as a `ChallengeFailed` finding on a `Failed` result. An outage or a configuration problem means Protect couldn't find out, and it's reported on an `Error` result instead.

| Code | Result | Finding Type | Meaning |
| --- | --- | --- | --- |
| `challenge-token-missing` | Failed | `ChallengeFailed` | No token was submitted, or it was too long to be genuine. |
| `challenge-rejected` | Failed | `ChallengeFailed` | The provider rejected the token, for example because it expired or was already used. |
| `challenge-context-mismatch` | Failed | `ChallengeFailed` | The token was issued for another action or hostname. |
| `challenge-score` | Failed | `ChallengeFailed` | The reCAPTCHA score was below `minimumScore`, or the hCaptcha Enterprise score was above `maximumScore`. |
| `provider-unavailable` | Error | `ProviderUnavailable` | The provider couldn't be reached or reported its own error. |
| `provider-http-error` | Error | `ProviderUnavailable` | The provider answered with an unexpected HTTP status. |
| `invalid-provider-response` | Error | `InvalidProviderResponse` | The provider's answer was malformed or missing a required field. |
| `provider-configuration` | Error | `ConfigurationError` | The secret key or site key is missing or wrong, or the key is the wrong type. |

Ask a visitor who failed to try again with a fresh form. For an outage, your plugin applies its own availability policy, which might mean returning a 503 or holding the submission for moderation. A `ConfigurationError` is different, because it won't fix itself. It means the site owner has to correct a key, so never let submissions through because of one. [Results and Findings](results-and-findings.md) shows how to put these branches together.

## When the Browser Can't Complete a Challenge

Sometimes the challenge can't run in the browser at all. The provider's script might be blocked by an extension or a Content Security Policy, the network might drop, or the visitor might have JavaScript turned off. In each case no token is submitted, and Protect reports `challenge-token-missing` as a failure, whatever availability policy your plugin applies to server-side outages.

This is deliberate. The server can't tell a genuine outage in the visitor's browser from a script that leaves the token out, so treating a missing token as an outage would let anyone skip the challenge. A form with a challenge can't be submitted successfully without JavaScript, and a headless client posting to your endpoint still needs a valid token. If the browser package's `prepare()` rejects, tell the visitor the verification couldn't load and keep their content in the form so they can try again. [When a Challenge Can't Complete](../developers/browser-package.md#when-a-challenge-can-t-complete) has more on this.

## Testing a Challenge End to End

Test with a real site key and secret key for a hostname you control. Add your development hostname, such as the `.ddev.site` address of your local project, to the widget or key in the provider's dashboard and to `hostnames`, and use a separate key from production if you prefer.

Cloudflare's published Turnstile test keys aren't suitable for testing Protect end to end. Their siteverify response doesn't include an `action` and always reports the hostname `example.com`, so Protect can't confirm the token was issued for your form and reports `invalid-provider-response`.
