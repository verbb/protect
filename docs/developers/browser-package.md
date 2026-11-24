# Browser Package

Protect's browser code mounts protection on a form, runs challenges such as Turnstile or hCaptcha, and collects the evidence a submission carries, meaning the signed preparation, the honeypot's value, the JavaScript flag and any challenge token. A form only needs it when its plan has a challenge or the JavaScript check, because the honeypot, minimum time, replay protection and rate limits all work from the hidden inputs and the server.

Protect ships its browser code two ways. A form that Twig renders and posts normally can use the script that ships ready-built inside the Composer package, so your plugin or site needs no npm and no build step. A form that your own script submits, or a front end with its own build, installs `@verbb/protect` from npm instead. Both are the same code, and both carry the same version as `verbb/protect`.

## Server-Rendered Forms

In a Twig form that posts normally, output the preparation with `renderBrowserInputs()` instead of `renderHiddenInputs()`. In this partial template, `preparation` is the result of `prepare()` for an article feedback form, and the action path belongs to your own plugin:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('example/feedback/send') }}
    {{ preparation.renderBrowserInputs() }}

    <textarea name="message" required></textarea>
    <button type="submit">Send Feedback</button>
</form>
```

`renderBrowserInputs()` outputs the same hidden inputs as `renderHiddenInputs()`, followed by an empty, hidden element holding the form's manifest (the public half of the preparation, which tells the browser code what to set up), and registers Protect's bundled script with Craft's view. Craft outputs the script just before the layout's closing `</body>` tag. When the page loads, the script mounts protection on every form containing a manifest, reusing the inputs already there.

When the visitor submits, the script holds the submission while it waits out any minimum time and runs the form's challenges. Then it sends the form normally, with the same submit button, so the button's own `name` and `value` still arrive, even if your page disabled or replaced the button in the meantime. While a submission is held, the form has `aria-busy="true"`, which you can style, and pressing submit again doesn't send it twice. Submit listeners that run after Protect's only see the released submission, which carries the evidence. If an earlier listener has already called `preventDefault()`, the script assumes your own code has taken over that submission and leaves it alone.

If protection can't complete, or hasn't completed after 150 seconds, the script dispatches a `protect:incomplete` event on the form and sends it anyway, without the missing evidence. Your server then refuses it through your plugin's normal response, and the visitor's input comes back to them. A missing token never gets a submission past a challenge, because the server checks for it. The [events](#events) from each form's browser controller are dispatched on the form too.

### Forms Added Later

The script watches the page, so a marked form added later, such as one loaded by Sprig, htmx or a modal, mounts as soon as it arrives, and a form whose marker is replaced mounts its new preparation. A fragment rendered without a full page, such as an AJAX response, doesn't output Craft's registered scripts, so make sure the host page loads the script itself:

```twig
{% do view.registerAssetBundle('verbb\\protect\\web\\assets\\protect\\ProtectAsset') %}
```

Registering the bundle this way doesn't add a CSP nonce. If your page has one, register the bundle from PHP instead, such as in the controller action that renders the page, and set the nonce on it with `Craft::$app->getView()->registerAssetBundle(ProtectAsset::class)->jsOptions['nonce'] = $nonce;`.

Calling `form.submit()` from your own code skips every submit listener, including the script's, so the form posts without its challenge evidence and is refused. Use `form.requestSubmit()` instead.

### The Global API

The script sets `window.VerbbProtect` with `mountProtection()`, `parseManifest()` and `registerChallengeProvider()`, the same functions the npm package exports, plus `mountForms(root)`, which mounts any marked forms under `root` that aren't mounted yet. Calling `window.VerbbProtect.mountProtection()` on a form the script already holds replaces its browser controller and keeps holding its submissions. If two plugins on one page load the script, the first copy handles both.

Don't use `renderBrowserInputs()` on a form your own script submits with `fetch()`. Use `renderHiddenInputs()` or a JSON preparation and mount the npm package yourself, as below, so your script decides when the form is sent.

## Your Own Script

`@verbb/protect` is a small ES module that doesn't depend on any framework. It adds Protect's fields to a form, loads provider scripts and manages each challenge widget. Your script stays in charge of sending the form, showing validation messages and retrying.

Your server sends the browser a preparation's manifest, usually as JSON from an uncached endpoint, as [Pages Served From a Cache](integrating-an-action.md#pages-served-from-a-cache) shows. In this partial example, `form` is the form element, its `data-action-url` attribute holds the URL of your plugin's action from Twig's `actionUrl()`, `data` is the JSON from your endpoint with its `manifest` and `csrfToken`, and `cspNonce` is your page's nonce, if it has one:

```ts
import { mountProtection, parseManifest } from '@verbb/protect';
import '@verbb/protect/styles.css';

const controller = mountProtection(form, parseManifest(data.manifest), { nonce: cspNonce });

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    // Waits out the minimum time and runs the challenge, then returns the evidence.
    const evidence = await controller.prepare();

    // A JSON body can't carry Craft's action parameter, so post to the action's own URL.
    await fetch(form.dataset.actionUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': data.csrfToken },
        body: JSON.stringify({ message: form.message.value, __protect: evidence }),
    });
});
```

`parseManifest()` checks the manifest has the shape the package expects, and `mountProtection()` adds Protect's hidden fields to the form and sets up any challenge. It returns a browser controller, the object your script uses to work with protection on that form from then on. Its `ready` promise settles once the challenge is set up. If a provider failed to start, such as on a dropped connection, `ready` rejects, and `prepare()` tries the provider again before giving up, so the form recovers without a reload.

`prepare()` waits for the minimum form age, runs the challenge and returns the evidence as an object, ready to send as JSON under `__protect`, which the server reads with `ProtectionEvidence::fromArray()`. The same values are in the form's hidden fields, so a script that sends `FormData` can send the form as it is. The stylesheet is optional. It positions the honeypot off-screen, so the field stays hidden even if your own CSS overrides its `hidden` attribute.

Each form gets its own browser controller, widget and fields, and provider scripts load once per page however many forms use them. Mounting a new manifest on the same form destroys the previous browser controller. Mount one preparation per form, because a shared preparation makes the second form's attempt look like a replay.

### Resetting and Removing

`reset()` clears challenge tokens and resets widgets, and a native form reset does the same. It doesn't renew the preparation, so it's no substitute for fetching a new one after an attempt. `destroy()` stops anything still running, removes the widgets and fields it added and releases its listeners. Call it before removing or replacing a form, so a provider doesn't hold on to a widget that's no longer on the page.

### Events

The browser controller dispatches these events on the form, each with the manifest's `instanceId` in its `detail`:

- `protect:ready` when every challenge is set up
- `protect:token` when a provider's token changes, with the provider's handle, including when a token is cleared after it expires, fails or is reset (the token itself isn't in the event)
- `protect:error` when a provider fails, with its handle and the provider's error `code` when it gives one, such as Turnstile's numeric codes, hCaptcha's `challenge-closed` or Friendly Captcha's `network_error`, or with neither when a challenge couldn't be set up at all
- `protect:waiting` when `prepare()` is waiting out the minimum time, with the remaining `wait` in milliseconds, so you can tell the visitor why the button paused
- `protect:reset` after `reset()`

### The Manifest and Evidence

A manifest holds `version`, `instanceId`, the signed `preparation`, `issuedAt` and `expiresAt`, `notBefore` when the plan has a minimum time, the random `honeypot` field name, the `javascript` flag and a `challenges` list with each provider's handle, public site key and action (empty for hCaptcha and Friendly Captcha), plus Friendly Captcha's `endpoint`. Its times are Unix timestamps in seconds. The form submits evidence under `__protect[version]`, `__protect[preparation]`, `__protect[honeypot][<name>]`, `__protect[javascript]` and `__protect[challenges][<provider>]`, and a JSON client can send the same values as a nested object.

## Expiry and the Visitor's Clock

A visitor's clock can be wrong by minutes, hours or a time zone. The browser controller measures every time on the server's clock, using an offset it works out from `issuedAt` when it first parses a manifest. Use the browser controller rather than comparing manifest times to `Date.now()`. `expiresIn()` returns the milliseconds until the preparation expires, or zero once it has, and `remainingWait()` returns the milliseconds until the minimum form age has passed.

The offset assumes the manifest is fresh, which is one more reason never to serve a preparation from a shared cache.

## Content Security Policy

Protect adds no inline scripts or styles, and hides its honeypot with the `hidden` attribute, so a policy without `unsafe-inline` works. Pass the page's nonce as `mountProtection(form, manifest, { nonce })`, and Protect sets it on the challenge provider's script, which a policy using `'nonce-…'` with `'strict-dynamic'` needs. For the bundled script, pass it as `renderBrowserInputs(nonce)` instead. Protect puts it on the bundled script's own tag, and the script passes it on to the provider's script. Also allow the provider's own script, frame and connection origins, as each provider documents them. The scripts Protect loads come from `challenges.cloudflare.com`, `www.google.com`, `js.hcaptcha.com` and, for Friendly Captcha, a pinned version of its SDK on `cdn.jsdelivr.net`.

A site that enforces Trusted Types (`require-trusted-types-for 'script'`) adds Protect's policy name to its list, such as `trusted-types verbb-protect`. That policy only creates the four providers' script URLs, so it can't be used to load anything else, and two copies of the package on one page share it. The provider's script may create policies of its own once loaded, so also allow any names its provider documents for it. If the page refuses the provider's script, the challenge can't complete, and the submission is refused like any other without a token.

## Refresh Before Expiry

A preparation lasts for the plan's `preparationTtl`. A page that stays open for a long time, such as a single-page app or a visitor writing a long reply, can fetch a new manifest before the old one expires and mount it on the same form, which replaces the old browser controller and keeps the form's own fields. Base the timing on `expiresIn()`, which is in milliseconds on the server's clock. For example, with the default lifetime of an hour, refreshing once `expiresIn()` drops below ten minutes leaves plenty of room for a slow request. Refresh again after any attempt that may have used the preparation, as [Retain Input During a Retry](#retain-input-during-a-retry) describes.

## How Turnstile Renders

Protect renders Turnstile with `execution: 'execute'` and `appearance: 'interaction-only'`. Turnstile's script loads and the widget renders when the form is mounted, but no challenge runs until a visitor submits, so a form nobody posts never asks the visitor anything. If Cloudflare needs an interactive challenge, the widget appears after the visitor clicks submit, so keep the form visible while `prepare()` is pending. After an error Cloudflare treats as permanent, such as a site key that doesn't match the hostname, `prepare()` rejects immediately until you call `reset()`.

## How hCaptcha Renders

Protect renders hCaptcha as an invisible widget with `render=explicit` and `recaptchacompat=off`, so hCaptcha doesn't define its own `grecaptcha` alongside a reCAPTCHA form on the same page. Nothing shows until a visitor submits. `prepare()` then runs the widget and waits up to two minutes, and if hCaptcha wants more proof, its puzzle opens over the page. Closing the puzzle rejects `prepare()` and dispatches `protect:error` with the code `challenge-closed`. hCaptcha's own response fields are renamed out of the form, so only Protect's evidence is submitted.

## How Friendly Captcha Renders

Protect creates the Friendly Captcha widget with `startMode: 'focus'`, so its puzzle starts the first time the visitor focuses a field in the form, and with no form field of its own, because the response goes into Protect's evidence. The widget is a visible box at the end of the form. `prepare()` uses a response that's already finished, waits for one that's still being solved, or starts a new puzzle when the last response has been used or has expired. After a wrong or missing site key, `prepare()` rejects immediately until you call `reset()`.

## When a Challenge Can't Complete

Sometimes a challenge can't run in the browser at all, because the provider's script is blocked, the connection drops or JavaScript is turned off. The submission then arrives without a token, and Protect reports `challenge-token-missing` as a failed check, not as a provider error. Your plugin's policy for provider outages only applies to failures Protect sees on the server, such as Cloudflare being unreachable when it verifies a token. A browser can't be trusted to report an outage, so treating a missing token as one would let anyone skip the challenge by leaving it out.

A server-rendered form can still be sent without JavaScript when its plan has only the honeypot, minimum time, replay protection and rate limits. A plan with the JavaScript check or a challenge refuses every submission without JavaScript, so decide deliberately which forms need one, and tell visitors near the form. A client posting to your endpoint without a browser still needs a valid token when the plan has a challenge.

## Retain Input During a Retry

Keep the form's content in place when a submission fails. Fetch a new manifest from your uncached preparation endpoint, then call `mountProtection(form, parseManifest(data.manifest), options)` again. Mounting replaces the old browser controller and leaves the visitor's own fields as they were. Keep the submit button disabled while the new preparation loads, show an error if the request fails, and wait for the new browser controller's `ready` promise before the next attempt.

Don't call `reset()` instead of fetching a new preparation. It clears widgets and tokens, but the old preparation stays used up. If a save may already have succeeded, use your plugin's idempotency handling before creating another attempt, as [Retry Without Losing Content](integrating-an-action.md#retry-without-losing-content) describes. An idempotent retry keeps its preparation and key but needs a new challenge token, because providers verify each token only once. `prepare()` asks the provider for a new token every time it runs, so calling it again before the retry is enough. Call `reset()` first only after an error the provider treats as permanent, such as a site key Turnstile or Friendly Captcha refuses.

## Custom Challenge Providers

A challenge provider that isn't built in needs a browser driver registered with `registerChallengeProvider(handle, factory)`, using the same handle as its PHP class. The factory receives the element to render into, the provider's public details from the manifest, an abort signal, the page's nonce and two callbacks, `onToken()` and `onError()`. It returns an object with `execute()`, `reset()` and `destroy()` methods. A driver must call `onToken()` with each token, because returning a token from `execute()` alone doesn't add it to the form. [Custom Challenge Providers](custom-challenge-providers.md#add-the-browser-driver) shows a complete driver.

The bundled script for server-rendered forms carries its own copy of the package, so a driver registered by importing `registerChallengeProvider` from `@verbb/protect` doesn't reach the forms it mounts. For those forms, register the driver with `window.VerbbProtect.registerChallengeProvider()` from a script that runs after the bundle. The bundle mounts forms as soon as it loads, so a form mounted before your driver was registered dispatches `protect:error`, then starts your provider when the visitor submits, because `prepare()` tries again.
