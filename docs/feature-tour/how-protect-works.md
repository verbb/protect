# How Protect Works

Any form that the public can post to will eventually attract spam. A guest comment form fills up with links to casinos, a contact form gets the same message a hundred times in a minute, and a review or voting form is submitted by a script rather than a person. Each of these needs a few familiar defences, such as a hidden field that only bots fill in, a check that the form wasn't submitted impossibly quickly, a limit on how often someone can post, and sometimes a CAPTCHA-style challenge or a spam filter.

Protect provides those defences for Craft plugins and site modules, so each plugin doesn't build and maintain its own. Your code describes which checks a form needs, Protect runs them when someone submits, and you get back a structured result that says what it found. Verbb's own plugins use it for guest comments and other public input, and you can use it the same way in your own plugin or in your site's module.

## Who Protect Is For

Protect is written for developers. You'll use it if you're writing a Craft plugin or module that accepts input from people you can't fully trust, which usually means guests, but can also mean signed-in members on a site where anyone can register. The examples in these docs assume you're comfortable with PHP and Twig and know how a Craft controller action handles a form.

If you run a Craft site and a plugin you've installed uses Protect, you don't need to install or configure Protect itself. The plugin's own settings cover things like Turnstile keys and how strict its forms are. Two parts of [Installation & Setup](../get-started/installation-setup.md) are still worth reading, because they affect every plugin that uses Protect on your site: [configuring your proxy](../get-started/installation-setup.md#configure-your-proxy-first), so Protect sees each visitor's real IP address, and [using a shared cache](../get-started/installation-setup.md#shared-cache-and-mutex) when the site runs on more than one server.

## What Protect Doesn't Do

Protect only examines an attempt and reports on it. It doesn't know what a comment, review or ticket is, and it never saves, deletes or moderates anything. It has no control panel screen and no database tables of its own. Deciding who may post, validating what they wrote, storing it, holding it for moderation and telling the visitor what happened all remain your plugin's job.

Protect also isn't a firewall or a bot-management service. It works at the level of one form submission in your application, so it complements rather than replaces protection at your CDN or host, such as Cloudflare's WAF.

## Prepare, Then Check

Protect works in two steps, which happen in two different requests.

Suppose your plugin lets visitors send feedback on an article. When the article page renders the feedback form, your code asks Protect to **prepare** the form. Protect returns a preparation, which is a small signed token describing this form, along with the hidden inputs it needs, such as the honeypot field. You output those inside your form next to Craft's CSRF input. If the form uses a challenge such as Turnstile, the preparation also includes a **browser manifest**, which the `@verbb/protect` browser package reads to set up the challenge.

When the visitor submits, your controller action validates the feedback as usual and then asks Protect to **check** the attempt, once, before saving anything. Your code passes Protect the submitted hidden inputs and any challenge token, and Protect runs each check and returns a result. If the result is clean, you save the feedback. If it isn't, the result tells you why, and you choose the response, such as asking the visitor to try again, telling them to slow down, or saving the message but holding it for review.

Both steps use the same **plan**, which is the list of checks for that form and their settings. You build it in one place in your code, so preparing and checking can't drift apart:

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;

$plan = (new ProtectionPlan(handle: 'article-feedback'))
    ->add(new Honeypot())
    ->add(new MinimumTime(['seconds' => 3]))
    ->add(new ReplayProtection())
    ->add(new RateLimit(['rules' => [
        new RateLimitRule(['limit' => 5, 'window' => 60]),
    ]]));
```

This plan rejects any submission whose hidden honeypot field was filled in, anything sent within three seconds of the form loading, any form that's submitted a second time, and more than five attempts a minute from one IP address. The [Installation & Setup](../get-started/installation-setup.md) page covers getting Protect into your project, and [Integrating an Action](../developers/integrating-an-action.md) shows the prepare and check code in full.

## The Checks

Each check catches a different kind of abuse, and they run from cheapest to most expensive. Passive checks only look at the submission itself, stateful checks consult the cache, and challenges and classification call an external service. By default the run stops at the first failure, so a bot that fills in the honeypot never costs you a call to Akismet.

| Check | What it catches | Needs |
| --- | --- | --- |
| [Honeypot](passive-checks.md) | Bots that fill in every field | Nothing |
| [Minimum time](passive-checks.md) | Scripts that submit faster than a person could | Nothing |
| [JavaScript presence](passive-checks.md) | Simple scripts that post without running JavaScript | The browser package |
| [Replay protection](replay-protection.md) | The same form being submitted again and again | The cache |
| [Rate limits](rate-limits.md) | Too many attempts from one visitor or address | The cache and a correct proxy setup |
| [Challenges](challenges.md) | Automated browsers, using Turnstile, reCAPTCHA v3, hCaptcha or Friendly Captcha | A provider account and the browser package |
| [Spam classification](spam-classification.md) | Spam written by people or bots that passed everything else | An Akismet, CleanTalk or OOPSpam key |

A typical public form starts with the honeypot, a minimum time, replay protection and a rate limit, which are free and invisible to real visitors. Add a challenge when those aren't enough, and spam classification when you'd rather catch spammy content than slow visitors down.

## Forms With and Without JavaScript

A form rendered by Twig and posted normally works with Protect without any JavaScript. The honeypot, minimum time, replay protection and rate limits all run from the hidden inputs and the server.

Challenges and the JavaScript presence check need Protect's browser code, because they run in the visitor's browser. It mounts the challenge on your form and, when the visitor submits, collects the evidence to send with it. A Twig form gets it by outputting the preparation with `renderBrowserInputs()`, which loads the script that comes with Protect, so there's nothing to build. A form your own script submits with `fetch()` uses the `@verbb/protect` npm package instead. See [Browser Package](../developers/browser-package.md) for both.

## Acting on Results

A check result lists **findings**, such as a failed passive check, a rate limit that's been exceeded or a message that Akismet thinks is spam. It also separates genuine findings about the visitor from operational errors, such as Akismet being unreachable or a missing secret key, because those need different responses. A visitor shouldn't be told they look like a bot because a provider had an outage. [Results and Findings](results-and-findings.md) explains how to turn a result into the right response.

To see all of this working together, [Protect a Feedback Form](../user-guides/protect-a-feedback-form.md) builds a complete plugin from an empty folder, and [Protect a Contact Form](../user-guides/protect-a-contact-form.md) does the same for a JavaScript form in your own site.
