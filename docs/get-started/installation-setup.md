# Installation & Setup

## Installation
Add the package as a Composer requirement of your plugin, or of your Craft project if a site module uses it:

```shell
composer require verbb/protect
```

```json
"require": {
    "craftcms/cms": "^5.10.0",
    "verbb/protect": "^1.0.0"
}
```

There's nothing to install in the control panel, because Protect isn't a Craft plugin. It has no settings screen, no project config and no database tables, and site owners never install it separately. Whatever requires it uses it, whether that's your plugin or your project's own module. [Protect a Feedback Form](../user-guides/protect-a-feedback-form.md) walks through a complete plugin, and [Protect a Contact Form](../user-guides/protect-a-contact-form.md) does the same for a module in your Craft project.

## Setup
Protect needs no bootstrap call. The first time your code calls `Protect::getInstance()`, Protect registers itself as a module and keeps that one instance for the rest of the request, so two plugins on the same site share it:

```php
use verbb\protect\Protect;

$protect = Protect::getInstance();
```

Your plugin's settings, such as provider keys and how strict each form should be, belong to your plugin. Protect receives them as part of the plan you build for each form, which [How Protect Works](../feature-tour/how-protect-works.md) explains.

### Configure Your Proxy First

Set this up before you rely on rate limits or any provider. Rate limits, the IP address the spam classifiers receive and a challenge provider's optional `remoteip` all use the visitor's IP address, so a site behind a proxy or CDN with the default configuration sees one edge address for every visitor. Everyone then shares one rate limit, and providers lose a useful signal.

Protect resolves the client address itself. It doesn't call Craft's `getUserIP()`, which reads `Client-IP` first and takes the leftmost forwarded entry, both of which a visitor controls. It uses Craft's own settings instead, so there's nothing Protect-specific to configure:

```php
// config/general.php
return GeneralConfig::create()
    ->trustedHosts(['10.0.0.0/8', '192.168.0.0/16']);
```

- `trustedHosts` must list your proxies as IP addresses or CIDR ranges. Protect can't match a hostname, so one never counts as trusted.
- With `trustedHosts` narrowed, and a connection from one of those addresses, Protect walks `X-Forwarded-For` from the right past your proxies. A connection from anywhere else uses `REMOTE_ADDR`, whatever headers it sends.
- To read other headers, list them in `ipHeaders`, in order. List headers (`X-Forwarded-For`, `Forwarded-For`, `X-Cluster-Client-IP`) are walked the same way. A single-value header such as `CF-Connecting-IP` is taken as it is. A `cidr => headers` entry in `trustedHosts` limits which of them count for that range.
- `Forwarded` (RFC 7239) isn't parsed.

With `trustedHosts` left at `any`, Protect uses `REMOTE_ADDR` and logs a warning, at most once a day, if forwarding headers arrive. The exception is a single-value header you've named in `ipHeaders`, such as `CF-Connecting-IP`, which is honoured with that same warning so a Cloudflare site keeps working. If your origin can be reached directly, a visitor can forge that header until you narrow `trustedHosts`.

If `REMOTE_ADDR` is missing or invalid, there's no client address, and rate rules keyed on it return an error finding with the code `rate-identity-missing`.

### Shared Cache and Mutex

Replay protection and rate limits remember what they've seen in Craft's configured cache, under Craft's configured mutex. A local file cache and file mutex only coordinate processes on one server, so a site with several web servers needs a shared cache and a shared mutex backend, such as Redis, that every server can see.

Replay claims and rate counters live only in the cache. Flushing it, or an eviction under memory pressure, resets rate windows and makes an already used form acceptable again until it expires, so avoid clearing the cache as routine maintenance on a busy site. If the cache or mutex fails, Protect returns an error result rather than throwing, and your code decides whether to refuse or allow the attempt. To use a different backend altogether, see [Replace the State Backend](../developers/extending-protect.md#replace-the-state-backend).

### Browser Package

A form rendered by Twig and posted normally needs no JavaScript from Protect. `renderHiddenInputs()` adds its hidden fields, and the honeypot is hidden with the `hidden` attribute, so there's no stylesheet to load either.

Challenges are different, because Turnstile, reCAPTCHA, hCaptcha and Friendly Captcha run in the visitor's browser, and so does the JavaScript presence check. Protect's Composer package includes its browser script ready-built. A Twig form outputs the preparation with `renderBrowserInputs()` instead of `renderHiddenInputs()`, and Protect loads the script for it, so there's nothing to install with npm and nothing to build.

A form your own script submits, or a front end with its own build such as a React or Vue app, can install the `@verbb/protect` npm package instead:

```shell
npm install @verbb/protect
```

It always carries the same version as the `verbb/protect` Composer package, because the browser builds the evidence that the server checks, so install the release that matches the site's. See [Browser Package](../developers/browser-package.md) for both approaches.
