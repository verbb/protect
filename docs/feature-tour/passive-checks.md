# Passive Checks

Most automated spam comes from generic scripts that find a form, fill in every field and post it as fast as they can. Passive checks catch that traffic by looking only at the submission and its signed preparation. They don't need the cache or an outside service, the visitor never sees them, and they run before every other kind of check, so a bot that trips one is turned away before it costs you a rate-limit counter or a call to Akismet.

Protect has three passive checks. `Honeypot` adds a hidden field that people never fill in, `MinimumTime` rejects a form submitted faster than a person could manage, and `JavaScriptPresence` requires the browser to have run JavaScript on the form.

## Adding Passive Checks to a Plan

Suppose your plugin accepts guest comments on articles. Build the plan in your plugin's protection service, so the template that renders the comment form and the controller that receives it use the same plan:

```php
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-comments'))
    ->add(new Honeypot())
    ->add(new MinimumTime(['seconds' => 3]))
    ->add(new JavaScriptPresence());
```

When the form renders, `prepare()` records what each check needs in the signed preparation, and `renderHiddenInputs()` outputs the matching fields. When the comment is posted, `check()` reads them back. [Integrating an Action](../developers/integrating-an-action.md) shows both halves of that code in full.

A passive check that fails produces a `PassiveCheckFailed` finding. The signed preparation is also verified when the first check that reads it runs, which is usually a passive check, so a form that has expired, been tampered with, or was prepared for a different action, article or plan fails here too, with the code `preparation-expired`, `preparation-invalid` or `preparation-mismatch`. [Results and Findings](results-and-findings.md) covers how to tell these apart and what to show the visitor.

## Honeypot

A honeypot is a text field that people can't see, but that a script reading the raw HTML will happily fill in. Protect names the field randomly for each preparation, so a bot can't learn one name and skip it across your forms. The rendered field sits in a wrapper with the `hidden` attribute and `aria-hidden="true"`, and the input itself has `tabindex="-1"` and `autocomplete="off"`, which keeps it away from keyboard users, screen readers and browser autofill without needing a stylesheet.

Any value in the field fails the check with the code `honeypot-populated`, even a single space. There's nothing to configure beyond the settings every check shares.

A honeypot is the cheapest check you can add, and it's worth having on every public form. It won't stop a script written specifically for your site, a headless browser that only fills in visible fields, or a person typing spam by hand. Those need the other checks on this page, a [challenge](challenges.md) or [spam classification](spam-classification.md). When a plan includes both a honeypot and Akismet, Protect also sends Akismet the honeypot's field name and value, which Akismet uses as an extra signal.

## Minimum Time

`MinimumTime` rejects a submission that arrives too soon after the form was prepared. A person needs at least a few seconds to read a form and write a comment, while a script can post the moment it has the page.

The `seconds` setting defaults to `3` and accepts any whole number from `0` to `300`. Protect measures the form's age on the server, from the time the preparation was issued to the time `check()` runs, so a visitor's wrong clock can't affect it. A submission made inside that time fails with the code `minimum-time`, and one made at or after it passes. There's no separate upper limit, because the plan's `preparationTtl` (an hour by default) already decides when a form expires.

Keep the number modest. A returning visitor whose browser fills in their name and email, and who writes a one-line reply, can submit faster than you might expect. Two or three seconds catches scripts without catching people.

A script that waits before posting will pass this check, so treat it as a filter for careless bots rather than proof that a person was there.

## JavaScript Presence

`JavaScriptPresence` requires the submission to carry a flag that only JavaScript sets. `renderHiddenInputs()` outputs the flag's field empty, and the `@verbb/protect` browser package sets it to `1` when it mounts on the form. A submission without that value fails with the code `javascript-missing`.

Many simple spam scripts post forms without running any JavaScript, so this check stops them cheaply. It doesn't stop a headless browser, which runs JavaScript like any other browser, or a script written for your form that sends the flag itself. It also needs the browser package on the page, and without it every submission fails. Add it when your form already depends on JavaScript, such as a form that posts with `fetch()`, and leave it out of forms that should keep working without scripts.

## Visitors Without JavaScript

A visitor with JavaScript turned off, or whose scripts failed to load, can still pass a honeypot and a minimum time, because both run entirely from the hidden inputs and the server. They can never pass `JavaScriptPresence`, and they can't complete a [challenge](challenges.md) either, so a plan with either of those rejects them every time.

If your form should work without JavaScript, leave `JavaScriptPresence` out of its plan. If it requires JavaScript, say so near the form, and when a result fails with `javascript-missing`, tell the visitor they need JavaScript to post rather than showing a generic error. A no-JavaScript visitor who submits too quickly sees whatever your plugin returns for `minimum-time`, so make that message ask them to wait a moment and submit again, and keep their text in the form.

## Native Forms and the Browser Package

A form rendered by Twig and posted normally needs only the hidden inputs. Output them inside the form, next to Craft's CSRF input:

```twig
{{ preparation.renderHiddenInputs() }}
```

The browser package changes how the passive checks behave in a few useful ways. It sets the JavaScript flag, and when you call `prepare()` before submitting, it waits out whatever remains of the minimum time instead of letting the server reject the attempt. While it waits, it emits a `protect:waiting` event with the remaining `wait` in milliseconds, which you can use to tell the visitor why the button is paused.

You can use both together without writing any JavaScript. Output the preparation with `renderBrowserInputs()` instead, and Protect loads its bundled script, which reuses the inputs already in the form and holds each submission until it's ready. In this Twig template, `preparation` is the result of `prepare()` for the comment form, and the action path belongs to your own plugin:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('example/comments/save') }}
    {{ preparation.renderBrowserInputs() }}

    <textarea name="text" required></textarea>
    <button type="submit">Post Comment</button>
</form>
```

With JavaScript running, the form's flag is set and the submit waits for the minimum time. Without it, the same form still posts its honeypot and preparation, and the server applies the minimum time itself. [Server-Rendered Forms](../developers/browser-package.md#server-rendered-forms) explains what the script does when a submission is held. Pages carrying a preparation must not be served from a shared cache, so a cached article page should fetch its preparation from your own endpoint instead, as the [browser package](../developers/browser-package.md) explains.

## Settings Every Check Shares

Every check accepts four settings in its config array alongside its own. `enabled` turns the check off without removing it from the plan, and `priority` orders checks within the same stage, lowest first, with a default of `0`. `condition` takes a closure that receives the `ProtectionContext` and returns `false` to skip the check for that attempt, which records it as `Skipped`. `continuation` overrides the plan's [continuation strategy](results-and-findings.md#continuation-strategies) after this check. A condition's code isn't part of the plan's fingerprint, so give the plan a new handle when you change one, otherwise forms prepared under the old condition stay valid.
