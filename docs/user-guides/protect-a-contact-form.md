# Protect a Contact Form

Protect doesn't need a plugin around it. Any PHP code in your Craft project can require it and use it, and that includes a site module, the code that lives in your own project rather than in a package you'd publish. This guide protects a single site's contact form with a module, which is the right approach when the form belongs to that one site and nowhere else.

The example is a small studio's website with a Contact page. A visitor fills in their name, email address and message and presses **Send message**. The page doesn't reload. A script sends the message to the module with `fetch()`, and the module answers in JSON, so the form can either thank the visitor in place or explain what went wrong while keeping everything they typed. Real messages arrive in the studio's inbox, and anything Akismet thinks is spam goes to a separate review inbox rather than disappearing, so a genuine enquiry that's wrongly flagged can still be answered.

By the end you'll have a module with a service that describes how the form is protected, a controller with two actions, a Contact page template and a front-end script built with Vite. The form uses a hidden honeypot field, a minimum time before it can be sent, a check that JavaScript ran, replay protection, rate limits, a Cloudflare Turnstile challenge and Akismet spam classification. The guide finishes by sending real messages and then trying each way the form refuses one, so you can see what a visitor sees in each case.

## What You Need

Start with a Craft 5.10 or later site running PHP 8.2 or later. The examples use [DDEV](https://ddev.com), so commands start with `ddev`. If you run Craft another way, drop that prefix and run the rest from your project root.

The site also needs these things before you start:

- A front-end build with npm and [Vite](https://vite.dev), and a layout template, such as `templates/_layout.twig`, that loads your built scripts. The guide adds one new entry point to that build.
- A [Cloudflare](https://dash.cloudflare.com) account, for Turnstile. Turnstile is free and doesn't need your site's DNS to be on Cloudflare.
- An [Akismet](https://akismet.com) API key. Akismet's free plan is for personal sites, so a business site needs one of its paid plans.
- Two email addresses, one for genuine enquiries and one for messages held for review. While you're developing, DDEV catches every email Craft sends in Mailpit, so these can be any addresses you like.

If your live site sits behind a CDN, a load balancer or any other proxy, configure Craft's `trustedHosts` before you rely on rate limits or Akismet, as [Configure Your Proxy First](../get-started/installation-setup.md#configure-your-proxy-first) explains. Otherwise every visitor arrives from the proxy's address, they all share one rate limit, and Akismet loses one of its best signals. If the site runs on more than one web server, also read [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex).

You should be comfortable with a little PHP, Twig and TypeScript. If you haven't written a module before, Craft's [module documentation](https://craftcms.com/docs/5.x/extend/module-guide.html) explains the idea, and this guide shows every file you need.

## How the Pieces Fit Together

Protect checks a single attempt to do something, such as sending a message, and reports what it found. It does this in two steps that happen in two different requests.

The first step is **preparation**, which happens when the form is shown. Protect issues a preparation, a small signed token that says “this form was issued at this time, for this action, with these checks”. It comes with a random name for the honeypot field and, because this form uses Turnstile, the public details the browser needs to run the challenge. All of that travels to the browser in a **browser manifest**, a JSON object that the `@verbb/protect` browser package reads to add its hidden fields to the form and get Turnstile ready.

The second step is the **check**, which happens when the visitor presses Send. The browser package runs the Turnstile challenge and gathers the **evidence**, which is the signed preparation, the honeypot's value, a flag saying JavaScript ran and the Turnstile token. Your script sends that alongside the message. Your controller hands the evidence to Protect, which runs every check and returns a **result**. The result doesn't decide anything by itself. It holds **findings**, such as “the honeypot was filled in” or “Akismet thinks this is spam”, and your module decides what each one means for this form.

Both steps use the same **plan**, the list of checks for this form and their settings. The module builds it in one place, so the preparation and the check can never disagree about which checks apply.

### What the Module Owns

Protect has no settings screen, no database tables and no endpoints of its own, and it never sends, saves or deletes anything. Everything around the checks belongs to your module. That includes validating the name, email and message, Craft's CSRF protection, what happens to a message that passes, what happens to one Akismet flags, and the wording the visitor sees for each outcome.

It also includes delivering the preparation. Because a preparation is signed for one visitor's attempt and expires after an hour, it can't be part of a page that might be cached. If your Contact page were stored in a full-page cache or at a CDN, every visitor would receive the same preparation, and only the first one to use it would get through. So the page itself contains nothing specific to the visitor, and the script asks the module for a fresh preparation, along with a CSRF token, from an endpoint that's never cached. The same endpoint gives the form a new preparation each time an attempt fails.

## Require Protect

Run these from your project root. The first adds Protect's PHP package to your Craft project, and the second adds the browser package to your front-end build:

```shell
ddev composer require verbb/protect
ddev npm install @verbb/protect
```

Install the version of `@verbb/protect` that matches your `verbb/protect` release, because the browser package and the PHP package read and write the same manifest and evidence.

Protect is a Composer library rather than a Craft plugin, so there's nothing to install or enable in the control panel afterwards. It doesn't need starting either. The first time your code calls `Protect::getInstance()`, Protect sets itself up as a Yii module and keeps that one instance for the rest of the request.

## Get Your Turnstile and Akismet Keys

Turnstile is Cloudflare's alternative to a CAPTCHA. Most visitors never see it, because it decides in the background whether the browser looks like a person's. When it isn't sure, it asks the visitor to tick a box.

In the Cloudflare dashboard, open **Turnstile** and add a widget. Name it something like “Studio contact form”, choose the **Managed** widget mode, and add every hostname the form will be used on, such as your live hostname `studio.example.com` and your DDEV hostname `studio.ddev.site`. Cloudflare then gives you a site key, which is public and goes to the browser, and a secret key, which stays on your server.

Use your own widget even while you're developing. Cloudflare publishes test keys that always pass, but its test secret keys answer without the action name and with the hostname `example.com`, so Protect can't confirm the token was meant for your form and treats every answer as an invalid response. A widget with your DDEV hostname added works locally exactly as it will on the live site.

Then copy your API key from your Akismet account. Add all of the keys to your project's `.env` file, along with the two email addresses, replacing these placeholder values with your own:

```shell
TURNSTILE_SITE_KEY="0x4AAAAAAA-your-site-key"
TURNSTILE_SECRET_KEY="0x4AAAAAAA-your-secret-key"
AKISMET_API_KEY="your-akismet-key"
CONTACT_EMAIL="hello@studio.example.com"
CONTACT_REVIEW_EMAIL="review@studio.example.com"
```

The module also uses `PRIMARY_SITE_URL`, which Craft's starter project already defines in `.env`. If yours doesn't have it, add it with your site's URL, such as `https://studio.ddev.site`.

## Autoload the Module

Modules live in your project, so Composer needs to know where to find their classes. Add an `autoload` section to your project's `composer.json`, or add the `modules\\` line to the one that's there:

```json
"autoload": {
  "psr-4": {
    "modules\\": "modules/"
  }
}
```

Then regenerate Composer's autoloader:

```shell
ddev composer dump-autoload
```

Every class in this guide lives under `modules/contactform`, with the namespace `modules\contactform`.

## Write the Module Class

Create `modules/contactform/Module.php`:

```php
<?php
namespace modules\contactform;

use modules\contactform\services\ContactProtection;

class Module extends \yii\base\Module
{
    public function init(): void
    {
        // The module's web controllers live in controllers/.
        $this->controllerNamespace = 'modules\\contactform\\controllers';

        parent::init();

        $this->setComponents([
            'protection' => ContactProtection::class,
        ]);
    }

    public function getProtection(): ContactProtection
    {
        return $this->get('protection');
    }
}
```

The module does very little itself. It tells Yii where its controllers are and registers one service, `protection`, which holds everything about how the form is protected. `getProtection()` is a typed shortcut to that service, which the controller uses. You'll write the service shortly.

## Register the Module

Craft only loads modules that are listed in `config/app.php`. Add yours under `modules`, with the ID `contact-form`:

```php
<?php
/**
 * Yii Application Config
 *
 * The array returned by this file will get merged with
 * vendor/craftcms/cms/src/config/app.php and app.[web|console].php.
 *
 * @link https://craftcms.com/docs/5.x/reference/config/app.html
 */

use craft\helpers\App;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',
    'modules' => [
        'contact-form' => \modules\contactform\Module::class,
    ],
];
```

The ID becomes the first part of the module's action URLs, so its controller's actions are reached at `actions/contact-form/messages/prepare` and `actions/contact-form/messages/send`. Craft loads the module whenever one of those URLs is requested.

Many modules are also added to `bootstrap`, which loads them on every request. This one doesn't need to be, because it only does something when one of its own actions runs, and Protect doesn't need anything registered in advance. If you later add event listeners to the module, such as one that registers a Twig variable, add `'bootstrap' => ['contact-form']` as well.

## Describe a Message

Before Protect sees anything, the module checks that the message itself is sensible. Create `modules/contactform/models/ContactMessage.php`:

```php
<?php
namespace modules\contactform\models;

use craft\base\Model;

class ContactMessage extends Model
{
    // Mixed on purpose, so a request that sends a list instead of text fails validation
    // rather than causing a PHP error.
    public mixed $name = null;
    public mixed $email = null;
    public mixed $message = null;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'email', 'message'], 'trim', 'skipOnArray' => true, 'skipOnEmpty' => true];
        $rules[] = [['name', 'email', 'message'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 100];
        $rules[] = [['email'], 'string', 'max' => 255];
        $rules[] = [['email'], 'email'];
        $rules[] = [['message'], 'string', 'max' => 5000];

        return $rules;
    }
}
```

The rules trim stray spaces, require all three fields, check the email address looks like one and cap each field's length. The properties are typed `mixed` because the values come straight from the request, and someone sending requests by hand could send a list where you expect text. With `mixed`, that becomes an ordinary “Name must be a string.” error instead of a PHP type error.

## Build the Protection Plan

The service is the heart of the module. It builds the plan, asks Protect to prepare and check attempts, and turns a result into a decision. Create `modules/contactform/services/ContactProtection.php`:

```php
<?php
namespace modules\contactform\services;

use modules\contactform\models\ContactMessage;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\UrlHelper;

use verbb\protect\Protect;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\FindingType;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionPreparation;
use verbb\protect\models\ProtectionResult;
use verbb\protect\models\RateLimitRule;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

class ContactProtection extends Component
{
    public const ACTION = 'site.contact.send';

    // What the controller does with a checked message.
    public const SEND = 'send';
    public const HOLD = 'hold';
    public const REFUSE = 'refuse';
    public const SLOW_DOWN = 'slow-down';
    public const UNAVAILABLE = 'unavailable';

    public function prepare(): ProtectionPreparation
    {
        return Protect::getInstance()->prepare(new ProtectionContext(self::ACTION, subject: $this->_subject()), $this->_plan());
    }

    public function check(ContactMessage $message, ProtectionEvidence $evidence, RequestSignals $request): ProtectionResult
    {
        return Protect::getInstance()->check(new ProtectionContext(
            action: self::ACTION,
            identity: new ProtectionIdentity(['email' => $message->email]),
            subject: $this->_subject(),
            content: new ProtectionContent(text: $message->message, authorName: $message->name),
            request: $request,
            evidence: $evidence,
        ), $this->_plan());
    }

    public function outcome(ProtectionResult $result): string
    {
        if ($result->retryAfter() !== null) {
            return self::SLOW_DOWN;
        }

        // Anything adverse apart from Akismet's verdict: the honeypot, timing, replay or Turnstile.
        if ($result->hasAdverseFindings() && !$result->hasFinding(FindingType::SpamDetected)) {
            return self::REFUSE;
        }

        // A wrong key or a missing setting needs fixing, so it never lets a message through.
        if ($result->hasFinding(FindingType::ConfigurationError)) {
            return self::UNAVAILABLE;
        }

        foreach ($result->findings() as $finding) {
            if ($finding->error && $finding->check !== Classification::class) {
                return self::UNAVAILABLE;
            }
        }

        // What's left is Akismet flagging the message, or Akismet being unreachable. Either way,
        // a person should read it before anyone replies.
        return $result->checksPassed() ? self::SEND : self::HOLD;
    }

    private function _plan(): ProtectionPlan
    {
        // Turnstile reports the hostname the form was on, which is this site's own.
        $host = parse_url((string)Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), PHP_URL_HOST);

        return (new ProtectionPlan(handle: 'site-contact-v1'))
            ->add(new Honeypot())
            ->add(new MinimumTime(['seconds' => 3]))
            ->add(new JavaScriptPresence())
            ->add(new RateLimit(['rules' => [
                new RateLimitRule(['handle' => 'burst', 'limit' => 3, 'window' => 600]),
                new RateLimitRule(['handle' => 'daily', 'limit' => 10, 'window' => 86400]),
            ]]))
            ->add(new ReplayProtection())
            ->add(new Challenge(['provider' => new Turnstile([
                'siteKey' => '$TURNSTILE_SITE_KEY',
                'secretKey' => '$TURNSTILE_SECRET_KEY',
                'expectedAction' => 'contact',
                'hostnames' => $host ? [$host] : [],
            ])]))
            ->add(new Classification(['provider' => new Akismet([
                'apiKey' => '$AKISMET_API_KEY',
                'blogUrl' => '$PRIMARY_SITE_URL',
                'contentType' => 'contact-form',
                'testMode' => App::devMode(),
            ])]));
    }

    private function _subject(): SubjectReference
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        return new SubjectReference('site', (string)$site->id, $site->id, UrlHelper::siteUrl('contact'));
    }
}
```

That's a lot to take in at once, so the next few sections go through it in parts.

### The Checks in the Plan

`_plan()` builds a new `ProtectionPlan` each time it's called, always with the same settings, so preparing and checking describe the same form. Each `add()` adds one check, configured with an array of settings.

**Honeypot** adds a text field that's hidden from people and from screen readers. A person never fills it in, but a bot that fills in every field it finds usually does. Its name is random for each preparation, so a bot can't learn to skip it.

**MinimumTime** refuses a message sent less than three seconds after the form was prepared, which is faster than a person can write anything. A real visitor never sees this as a refusal, because the browser package waits out whatever is left of the three seconds before it sends. Only a script that skips the browser package gets caught.

**JavaScriptPresence** requires the flag the browser package adds when it runs. This form needs JavaScript to send at all, so requiring it costs nothing, and it stops the simplest bots that post the form without running the page's scripts.

**RateLimit** counts attempts from each visitor's IP address. The two rules allow three attempts in ten minutes and ten in a day, and each rule needs its own `handle`. The counts are kept separately for each action and site, and an IPv6 visitor is counted by their whole /64 network, because one person usually controls all of it. The windows are fixed rather than rolling, so the ten-minute windows start at 10:00, 10:10 and so on, and the daily one starts at midnight UTC. Someone who sends three messages at 10:09 can send three more at 10:10, which is why the plan pairs the ten-minute rule with a daily one. [Fixed Windows](../feature-tour/rate-limits.md#fixed-windows) explains the trade-off.

**ReplayProtection** makes each preparation usable once. A bot that captures one successful submission can't send it again and again. This is why the front-end script fetches a new preparation after every attempt, as you'll see later.

**Challenge** with a **Turnstile** provider verifies the Turnstile token with Cloudflare. The `$TURNSTILE_SITE_KEY` and `$TURNSTILE_SECRET_KEY` values are names of environment variables, which Protect looks up in `.env` when it needs them, so the keys never appear in your code. The secret key never reaches the browser. `expectedAction` is a name for this form, which the browser passes to Cloudflare and Cloudflare reports back, so a token earned on another form can't be used here. `hostnames` lists the hostnames a token may come from. The module reads it from the current site's base URL, so it's right in each environment without another setting. If the form is used on more than one hostname, list them all instead.

**Classification** with an **Akismet** provider sends the message to Akismet, which answers whether it's spam. `contentType` tells Akismet this is a contact form rather than a blog comment. `blogUrl` is your site's URL, read from `PRIMARY_SITE_URL`. `testMode` is on while Craft's dev mode is, which tells Akismet that the messages are tests so it doesn't learn from them.

Protect runs the checks in stages rather than strictly in the order you add them. The cheap checks run first (the honeypot, minimum time and JavaScript checks), then the checks that record something, then Turnstile and finally Akismet. Within a stage, checks run in the order you add them, so this plan's rate limit counts an attempt before replay protection looks at it, and a replayed request still uses up one of the visitor's attempts. That's deliberate here, because a script replaying one captured request quickly runs into the rate limit as well. Adding replay protection first would refuse a replay without counting it. If a check fails, the rest are skipped, so an obvious bot is turned away before anything is counted or sent to Cloudflare or Akismet. A check that couldn't run, such as Turnstile during an outage, doesn't stop the others, and the result reports it as an error rather than a failure. [Results and Findings](../feature-tour/results-and-findings.md) explains the difference in more detail.

### What Each Attempt Is Bound To

`prepare()` and `check()` both build a `ProtectionContext`, which describes the attempt to Protect. The two must agree, or Protect refuses the attempt, and three parts of it bind a preparation to this form.

The **action**, `site.contact.send`, names what's being attempted. A preparation issued for this form can't be used for another form that also uses Protect, even on the same site.

The **subject**, from `_subject()`, says what the attempt is about. For a feedback form that's usually the article, but a contact form is about the site itself, so the subject is the current site. That keeps preparations and rate limits for each site separate on a multi-site install. Its last argument is the Contact page's URL, which Akismet receives as the page the message came from.

The **plan handle**, `site-contact-v1`, together with the plan's settings, also forms part of what each preparation is signed for. If you change the plan later, anyone with the form already open gets one refusal, the form fetches a fresh preparation, and their next press of Send works.

`check()` adds the rest of what the checks need. `ProtectionIdentity` passes the visitor's email address to Akismet, and `ProtectionContent` passes the message and their name. The `RequestSignals`, which the controller collects, carry the visitor's IP address, browser and referring page. Akismet needs the IP address and works better with the others. The email address only informs Akismet. Never use something a visitor typed as the identity a rate limit counts against, because they can type something different each time.

### Decide What Happens to a Message

`outcome()` turns a result into one of five decisions for the controller. The rules are this module's own choices, made for a contact form, and a different form could reasonably choose differently.

| Outcome | When it happens | Response | What the visitor sees |
| --- | --- | --- | --- |
| `send` | Every check passed | 200 | A thank-you message |
| `hold` | Akismet flagged the message, or couldn't be reached | 200 | The same thank-you message |
| `refuse` | The honeypot, minimum time, JavaScript or replay check failed, the preparation had expired, or Turnstile refused the token | 400 | “We couldn’t send your message. Please try again.” |
| `slow-down` | A rate limit was exceeded | 429 | A request to wait a few minutes |
| `unavailable` | A key or setting is wrong, Turnstile couldn't be reached, or the cache that holds rate counts is down | 503 | A request to try again in a few minutes |

The order matters. `retryAfter()` comes first, because a rate limit is a failure too and deserves its own message. `hasAdverseFindings()` is true when any check failed, and every failure except Akismet's verdict means the attempt isn't trustworthy. A `ConfigurationError` finding means a key is wrong or missing, which won't fix itself, so the form stops accepting messages until you fix it rather than letting them through unchecked. Any other error, apart from Akismet's, means a check couldn't run, and the module won't send a message that Turnstile never verified.

Akismet is the exception on purpose. By the time it runs, Turnstile has already confirmed a person is probably there, so if Akismet can't be reached, the message is held for review rather than lost. A held message gets the same thank-you as a sent one, because telling a spammer their message was flagged only helps them adjust it, and a genuine visitor who's wrongly flagged will still get a reply once someone reads the review inbox.

## Add the Controller

The controller has two actions. `prepare` gives the form a fresh preparation and CSRF token, and `send` receives the message. Create `modules/contactform/controllers/MessagesController.php`:

```php
<?php
namespace modules\contactform\controllers;

use modules\contactform\Module;
use modules\contactform\models\ContactMessage;
use modules\contactform\services\ContactProtection;

use Craft;
use craft\helpers\App;
use craft\web\Controller;

use verbb\protect\errors\PreparationException;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\RequestSignals;

use yii\web\Response;

class MessagesController extends Controller
{
    protected array|bool|int $allowAnonymous = ['prepare', 'send'];

    public function actionPrepare(): Response
    {
        $preparation = Module::getInstance()->getProtection()->prepare();

        // A preparation belongs to one visitor and one attempt, so nothing may store this response.
        $this->response->setNoCacheHeaders();

        return $this->asJson([
            'manifest' => $preparation->browserManifest(),
            'csrfToken' => $this->request->getCsrfToken(),
        ]);
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();

        $protection = Module::getInstance()->getProtection();

        $contact = new ContactMessage([
            'name' => $this->request->getBodyParam('name'),
            'email' => $this->request->getBodyParam('email'),
            'message' => $this->request->getBodyParam('message'),
        ]);

        // Ordinary validation first. Nothing has been counted or claimed yet.
        if (!$contact->validate()) {
            return $this->_respond(400, 'Please check the highlighted fields.', ['errors' => $contact->getErrors()]);
        }

        try {
            $evidence = ProtectionEvidence::fromArray($this->request->getBodyParam('__protect'));
        } catch (PreparationException) {
            return $this->_respond(400, 'This form needs refreshing. Please try again.');
        }

        // Akismet needs the visitor's IP, and works better with their browser and the page they came from.
        $request = RequestSignals::fromRequest($this->request, userAgent: true, referrer: true);
        $result = $protection->check($contact, $evidence, $request);
        $outcome = $protection->outcome($result);

        if ($outcome === ContactProtection::SLOW_DOWN) {
            $this->response->getHeaders()->set('Retry-After', (string)$result->retryAfter());

            return $this->_respond(429, 'You’ve sent several messages in a short time. Please wait a few minutes and try again.', ['retryAfter' => $result->retryAfter()]);
        }

        if ($outcome === ContactProtection::REFUSE) {
            return $this->_respond(400, 'We couldn’t send your message. Please try again.');
        }

        if ($outcome === ContactProtection::UNAVAILABLE) {
            return $this->_respond(503, 'We can’t check messages right now. Please try again in a few minutes.');
        }

        if (!$this->_deliver($contact, $outcome === ContactProtection::HOLD)) {
            return $this->_respond(500, 'We couldn’t send your message. Please try again.');
        }

        // A held message gets the same reply, so a sender can't tell it was flagged.
        return $this->_respond(200, 'Thanks for your message. We’ll be in touch soon.');
    }

    private function _deliver(ContactMessage $contact, bool $held): bool
    {
        $body = "Name: {$contact->name}\nEmail: {$contact->email}\n\n{$contact->message}";

        if ($held) {
            $body = "Akismet flagged this message as likely spam, or couldn’t be reached to check it. Reply only if it looks genuine.\n\n" . $body;
        }

        return Craft::$app->getMailer()->compose()
            ->setTo(App::env($held ? 'CONTACT_REVIEW_EMAIL' : 'CONTACT_EMAIL'))
            ->setReplyTo($contact->email)
            ->setSubject($held ? '[Held for review] Website enquiry' : 'Website enquiry')
            ->setTextBody($body)
            ->send();
    }

    private function _respond(int $status, string $message, array $data = []): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson(['message' => $message] + $data);
    }
}
```

`$allowAnonymous` lets visitors who aren't signed in use both actions, which a contact form needs. Craft still checks the CSRF token on every POST.

### The Prepare Action

`actionPrepare()` asks the service for a preparation and returns its browser manifest with a CSRF token. `setNoCacheHeaders()` marks the response so browsers, proxies and CDNs never store it. If your site has a CDN rule that caches everything, make sure it leaves `/actions/` URLs alone.

The CSRF token comes from this response for the same reason the manifest does. A token written into a cached page would belong to whoever's visit filled the cache. Fetching it here gives each visitor their own, and the script sends it back in an `X-CSRF-Token` header, which Craft accepts in place of a form field.

If the Turnstile site key is missing from `.env`, `prepare()` throws an `InvalidPlanException` saying the site key is empty, and this action returns an error. That's deliberate. A form without a site key could never pass Turnstile, so it's better to find out when you open the page than when a visitor presses Send.

### The Send Action

`actionSend()` works through the attempt in a fixed order, and the order is part of the design.

It validates the message first. A visitor who leaves out their email address gets a 400 response with the field errors, and because Protect hasn't run yet, nothing has been counted against their rate limit and the preparation hasn't been used.

Then it reads the evidence. The script sends it under `__protect`, and `ProtectionEvidence::fromArray()` checks its shape. Anything that isn't valid evidence, including a request with no `__protect` at all, throws a `PreparationException`, which the action turns into a 400 response.

Then it runs the check and asks the service for the outcome. Protect runs the check exactly once per attempt and only after validation passes, because the rate limit counts each check and replay protection uses up the preparation. A 429 response includes a `Retry-After` header, the number of seconds until the visitor's limits have all cleared.

Finally, a message that's sent or held goes out by email. `_deliver()` sends genuine messages to `CONTACT_EMAIL` and held ones to `CONTACT_REVIEW_EMAIL`, with a subject that says so and a note at the top of the body. The reply-to address is the visitor's, so you can answer from your email program. The visitor's name stays in the body rather than the subject line, which keeps anything they typed out of the email's headers.

If you'd rather keep held messages on the site, save them somewhere with a flag instead, such as an entry in a Contact Messages section with a Held for Review lightswitch. The result only says what Protect found, and the module decides where each message goes.

## Add the Contact Page

Create `templates/contact.twig`. Craft serves a template at `templates/contact.twig` at your site's `/contact` URL, which is also the URL the service gives Akismet. This site's templates extend its own `_layout.twig`, which holds the page's `<html>`, `<head>` and `<body>`. Yours can extend whatever layout your site uses.

```twig
{% extends '_layout' %}

{% block content %}
    <h1>Contact Us</h1>

    <form id="contact-form" method="post"
        data-prepare-url="{{ actionUrl('contact-form/messages/prepare') }}"
        data-send-url="{{ actionUrl('contact-form/messages/send') }}">
        <p data-status role="status"></p>

        <label for="contact-name">Name</label>
        <input id="contact-name" type="text" name="name" autocomplete="name" maxlength="100" required>
        <p data-error-for="name"></p>

        <label for="contact-email">Email</label>
        <input id="contact-email" type="email" name="email" autocomplete="email" required>
        <p data-error-for="email"></p>

        <label for="contact-message">Message</label>
        <textarea id="contact-message" name="message" rows="8" maxlength="5000" required></textarea>
        <p data-error-for="message"></p>

        <button type="submit" disabled>Send message</button>
    </form>

    <noscript>
        <p>Our contact form needs JavaScript. You can also email us at hello@studio.example.com.</p>
    </noscript>

    {{ craft.vite.script('src/js/contact.ts') }}
{% endblock %}
```

Nothing on this page is specific to the visitor, so the page can be cached like any other. `actionUrl()` writes the two action URLs into `data-` attributes for the script to read. There's no CSRF input, because the token comes from the prepare action, and no Protect fields, because the browser package adds those itself.

The paragraph marked `data-status` shows progress and errors, and its `role="status"` means screen readers announce each change. Each `data-error-for` paragraph shows the first validation error for its field. The `required` and `maxlength` attributes let the browser catch most mistakes before anything is sent, although the module checks them again, because a browser's checks are easy to skip.

The **Send message** button starts disabled and the script enables it. A visitor without JavaScript sees a button that does nothing and the `<noscript>` message, rather than a form that would only be refused.

The last line loads the script you'll write next. It uses the [Vite plugin for Craft](https://nystudio107.com/docs/vite/). If your layout loads Vite entry points another way, load `src/js/contact.ts` the same way your other entries are loaded.

## Write the Front-End Script

Create `src/js/contact.ts` in your front-end source folder:

```ts
import { mountProtection, parseManifest, type ProtectionController } from '@verbb/protect';
import '@verbb/protect/styles.css';

type PrepareResponse = { manifest: unknown; csrfToken: string };
type SendResponse = { message?: string; errors?: Record<string, string[]> };

const contactForm = document.querySelector<HTMLFormElement>('#contact-form');

if (contactForm) {
    setUpContactForm(contactForm);
}

function setUpContactForm(form: HTMLFormElement): void {
    const button = form.querySelector<HTMLButtonElement>('button[type="submit"]')!;
    const status = form.querySelector<HTMLElement>('[data-status]')!;

    // On a page with a Content Security Policy nonce, Protect gives it to Turnstile's script.
    const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce || undefined;

    let controller: ProtectionController | null = null;
    let csrfToken = '';
    let stale = true;

    const showStatus = (text: string, isError = false): void => {
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    };

    const showFieldErrors = (errors: Record<string, string[]> = {}): void => {
        form.querySelectorAll<HTMLElement>('[data-error-for]').forEach((element) => {
            element.textContent = errors[element.dataset.errorFor ?? '']?.[0] ?? '';
        });
    };

    const value = (name: string): string => (form.elements.namedItem(name) as HTMLInputElement | HTMLTextAreaElement).value;

    // Fetches a fresh preparation and mounts it, replacing the previous one. The visitor's own
    // fields are left as they are.
    async function refreshProtection(): Promise<ProtectionController> {
        const response = await fetch(form.dataset.prepareUrl!, { headers: { Accept: 'application/json' }, cache: 'no-store' });

        if (!response.ok) {
            throw new Error('The form couldn’t be prepared.');
        }

        const data = (await response.json()) as PrepareResponse;
        csrfToken = data.csrfToken;
        controller = mountProtection(form, parseManifest(data.manifest), { nonce });
        stale = false;

        // If Turnstile's script didn't load, prepare() tries again when the visitor presses Send.
        await controller.ready.catch(() => undefined);

        return controller;
    }

    form.addEventListener('protect:waiting', () => showStatus('Just a moment…'));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (button.disabled) {
            return;
        }

        button.disabled = true;
        showFieldErrors();
        showStatus('Sending…');

        try {
            // Use a fresh preparation after a failed attempt, or when this one is about to expire.
            const active = stale || !controller || controller.expiresIn() < 30000 ? await refreshProtection() : controller;
            const evidence = await active.prepare();

            const response = await fetch(form.dataset.sendUrl!, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ name: value('name'), email: value('email'), message: value('message'), __protect: evidence }),
            });
            const data = (await response.json().catch(() => ({}))) as SendResponse;

            if (response.ok) {
                active.destroy();

                const thanks = document.createElement('p');
                thanks.setAttribute('role', 'status');
                thanks.textContent = data.message ?? 'Thanks for your message.';
                form.replaceWith(thanks);

                return;
            }

            stale = true;
            showFieldErrors(data.errors);
            showStatus(data.message ?? 'We couldn’t send your message. Please try again.', true);
        } catch {
            stale = true;
            showStatus('We couldn’t send your message. Check your connection, then press Send to try again.', true);
        }

        // Mount a fresh preparation now, so the next press doesn't have to wait for one.
        await refreshProtection().catch(() => undefined);
        button.disabled = false;
    });

    refreshProtection()
        .catch(() => undefined)
        .finally(() => {
            button.disabled = false;
        });
}
```

The script is written in TypeScript, which Vite builds without any extra setup. If your site uses plain JavaScript, name the file `contact.js` and remove the type annotations, such as `: void`, `as PrepareResponse` and the `type` lines at the top.

Then add the new file to your Vite build's entry points. In most Vite setups for Craft, that means adding `src/js/contact.ts` to `build.rollupOptions.input` in `vite.config.js`, beside your existing entries.

### Mounting Protection

The two imports bring in the browser package and its small stylesheet. The stylesheet positions the honeypot off-screen, so it stays invisible even if your own CSS overrides the `hidden` attribute it already carries.

`refreshProtection()` fetches a preparation from the prepare action, with `cache: 'no-store'` so the browser doesn't reuse an earlier answer. `parseManifest()` checks the manifest has the shape the package expects, and `mountProtection()` adds Protect's hidden fields to the form, loads Turnstile's script and sets up its widget. It returns a **controller**, the object the script uses to work with protection on this form from then on.

`controller.ready` is a promise that settles once Turnstile is set up. If Turnstile's script couldn't load, such as on a dropped connection, `ready` rejects. The script carries on regardless, because the controller tries to load Turnstile again when the visitor presses Send, so a form recovers without a reload.

The script calls `refreshProtection()` once when the page loads and enables the button when it finishes. If that first request fails, `stale` stays `true`, so the first press of Send tries it again.

### Sending a Message

When the visitor presses **Send message**, the browser checks the `required` fields first and only fires the `submit` event once they're filled in. The script stops the browser's own submission with `preventDefault()`, disables the button so a double click can't send twice, and shows “Sending…”.

Before sending, it makes sure the preparation is usable. If the last attempt failed, or the preparation has less than 30 seconds left, it fetches a new one. `expiresIn()` gives the time left in milliseconds, measured on the server's clock rather than the visitor's, so a visitor whose computer clock is wrong still gets the right answer.

Then it awaits `prepare()`. This is the moment Turnstile runs. Protect sets Turnstile to run on request and to appear only when it needs the visitor, so for most people nothing visible happens and `prepare()` finishes in a second or two. When Cloudflare does want an interaction, Protect shows the widget at the bottom of the form, and `prepare()` waits until the visitor has ticked the box. That's one reason the form stays on the page while the message is sending. `prepare()` also waits out the minimum time. If the visitor sends within three seconds of the form being prepared, the controller emits a `protect:waiting` event, which the script turns into “Just a moment…”.

`prepare()` returns the evidence as an object, ready to send as JSON. The script sends it as `__protect` alongside the visitor's fields, which is the shape `ProtectionEvidence::fromArray()` reads on the server. The evidence is also in the form's hidden fields, so a script that sends `FormData` instead of JSON can send the form as it is.

### Handling the Answer

A successful response means the message was sent or held. The script calls `destroy()` on the controller, which removes Turnstile's widget and Protect's fields and stops anything still running, then replaces the form with the thank-you message from the response. Calling `destroy()` before removing a form keeps Turnstile from holding on to a widget that's no longer on the page.

Any other response means this attempt is over. The script shows the response's message and any field errors and marks the preparation as stale, because a refused attempt may already have used it up. Then it fetches a fresh preparation and mounts it on the same form before enabling the button again. Mounting a new manifest replaces the previous controller automatically and leaves the visitor's name, email and message exactly as they were, so they can press Send again without retyping anything.

The `catch` block handles anything that stopped the attempt before a response arrived, such as a lost connection or a Turnstile challenge that couldn't complete. A challenge that can't complete is always treated as a failed attempt rather than waved through, because the browser can't prove an outage happened, and a bot could claim one just as easily.

There's one case where a second press could send a second email. If the connection drops after the server has sent the email but before the response arrives, the visitor sees the connection message and may press Send again. For a contact form, an occasional duplicate is harmless. A form that creates orders or accounts would need an idempotency key instead, as [Retry Without Losing Content](../developers/integrating-an-action.md#retry-without-losing-content) describes.

## If Your Site Sends a Content Security Policy

A Content Security Policy is a header that tells browsers which scripts, frames and other resources a page may load. If your site doesn't send one, skip this section. If it does, the Contact page needs three things from it.

First, Turnstile's script must be allowed. Protect adds no inline scripts or styles of its own, but it loads Turnstile's script from `https://challenges.cloudflare.com`. If your policy uses a nonce, a random value your layout adds to each of its `<script>` tags, the script above finds it on the page and passes it to `mountProtection()`, and Protect puts the same nonce on Turnstile's script. That's what a policy using `'nonce-…'` with `'strict-dynamic'` needs. A policy that lists allowed hosts instead needs `https://challenges.cloudflare.com` in `script-src`.

Second, Turnstile's widget is a frame, so `frame-src` must allow `https://challenges.cloudflare.com`.

Third, if your policy enforces Trusted Types, add Protect's policy name, `verbb-protect`, to your `trusted-types` directive. Trusted Types makes the browser refuse any script URL that hasn't come through a named policy. Protect's policy can only produce its challenge providers' script URLs, so allowing it doesn't let anything else load. Turnstile's own script works with that name alone.

For a site with a nonce-based policy, the relevant parts of the header look like this, with your layout's own nonce and any other names your site already allows:

```text
Content-Security-Policy: script-src 'nonce-RANDOM' 'strict-dynamic'; frame-src https://challenges.cloudflare.com; object-src 'none'; base-uri 'none'; require-trusted-types-for 'script'; trusted-types verbb-protect
```

If the policy refuses Turnstile's script, the challenge can't run, so pressing Send always ends with the connection message and your browser's console reports the blocked resource. [Content Security Policy](../developers/browser-package.md#content-security-policy) covers the package's behaviour in more detail.

## Send Your First Message

Build your front-end assets, or start Vite's development server, and open `/contact` on your DDEV site. The form looks like any other, and **Send message** becomes enabled a moment after the page loads.

Open your browser's developer tools on the **Network** tab and reload the page. You'll see a request to `actions/contact-form/messages/prepare`. Its response is the JSON from the prepare action, with a `manifest` holding the signed `preparation`, a random `honeypot` name, `"javascript": true`, and a `challenges` list with your Turnstile site key and the action `contact`. The response headers include `Cache-Control: no-cache, no-store, must-revalidate`. Reload again and the preparation, the honeypot name and the times all change.

Fill in the form with sample details, such as the name “Sam Taylor”, the email address `sam@example.com` and the message “Could you quote for a kitchen renovation?”, and press **Send message**. The status reads “Sending…” while Turnstile runs, and a moment later the form is replaced by “Thanks for your message. We’ll be in touch soon.”. In the Network tab, the `send` request shows the JSON the script sent, with your fields and a `__protect` object, and a 200 response.

Now open Mailpit with `ddev launch -m`. A message titled “Website enquiry” is waiting for `CONTACT_EMAIL`, with Sam's details in the body. Use your email program's reply, and it goes to `sam@example.com`.

If the button stays disabled, check the browser's console for errors, and check the prepare request. An error response there usually means `TURNSTILE_SITE_KEY` is missing from `.env`, because the plan can't be prepared without it. The other keys are only used when a message is sent. If pressing Send always ends with “We couldn’t send your message. Check your connection, then press Send to try again.” and the console shows Turnstile error 110200, the hostname you're using isn't on your Turnstile widget. Add it in the Cloudflare dashboard and try again.

## See a Message Held for Review

Akismet treats one name as guaranteed spam, so you can test what happens to a flagged message. Reload the Contact page, enter `akismet-guaranteed-spam` as the name with any email address and message, and press **Send message**.

The visitor sees exactly the same thank-you message as before. In Mailpit, though, this one went to `CONTACT_REVIEW_EMAIL` with the subject “[Held for review] Website enquiry”, and the body starts with the note explaining why. The message wasn't discarded, so if Akismet ever flags a real enquiry, someone reading the review inbox can still answer it.

## Check What the Form Refuses

Each check is worth seeing hold. In every case below, the visitor's text stays in the form, the status explains what happened, and the button comes back once a fresh preparation has arrived.

The rate limit counts every attempt that gets past the honeypot, minimum time and JavaScript checks, including the messages you sent in the last two sections. Clear the counts before you start, so the limit doesn't get in the way of the other tests:

```shell
ddev craft clear-caches/data
```

The counts are kept in Craft's data cache, which is why this resets them. Don't make clearing that cache routine on a live site, because it resets every visitor's limits and lets a used preparation be used again until it expires.

### A Bot Fills In the Honeypot

Reload the Contact page and fill in the form. Before pressing Send, open the browser's console and fill in the hidden field the way a bot would:

```js
document.querySelector('.protect-honeypot input').value = 'https://spam.example';
```

Press **Send message**. The status shows “We couldn’t send your message. Please try again.” in place of the thank-you, the `send` request in the Network tab has a 400 response, and nothing arrives in Mailpit. The form fetches a new preparation with a new, empty honeypot field, so pressing Send again sends the message normally.

### A Visitor Sends Too Quickly

Reload the page, paste a message into each field and press Send within three seconds. The status changes from “Sending…” to “Just a moment…” until the three seconds are up, and then the thank-you appears. The minimum time is enforced in the browser by waiting rather than by refusing, so a quick visitor is slowed down a little instead of turned away.

### Someone Sends the Same Request Twice

In this plan the rate limit runs before replay protection, so a replayed copy is counted too, and by now you've used two of the three attempts the burst rule allows. Clear the counts again with `ddev craft clear-caches/data`, so the copy reaches replay protection instead of the rate limit. Then send a message successfully and find its `send` request in the Network tab. In Chrome, right-click it and choose **Copy → Copy as fetch**, then paste that into the console and run it. It's a perfect copy of a request that worked a moment ago, but the response is a 400 with “We couldn’t send your message. Please try again.” and no second email arrives. Replay protection used up that preparation the first time, so captured requests can't be sent again.

### A Visitor Sends Too Many Messages

The burst rule allows three attempts in ten minutes. Clear the counts again with `ddev craft clear-caches/data`, then send three messages, reloading the page after each thank-you, and send a fourth. This time the status shows “You’ve sent several messages in a short time. Please wait a few minutes and try again.”, the response is a 429 with a `Retry-After` header, and the message is still in the form. The windows are fixed, so if a new ten-minute window starts while you're sending, the count starts again and the fourth goes through. Send all four within a few minutes.

### A Visitor Has JavaScript Turned Off

Turn off JavaScript in your browser and reload the page. **Send message** stays disabled and the `<noscript>` message suggests emailing instead. Without JavaScript, the form couldn't pass the JavaScript and Turnstile checks anyway, so it doesn't pretend otherwise.

## When Turnstile or Akismet Can't Help

The last cases are the ones where a service isn't available or a key is wrong. They're worth trying once, so you know what you and your visitors will see. The burst test left you at the rate limit, so clear the counts first with `ddev craft clear-caches/data`, or every attempt here gets the rate-limit message instead.

### Turnstile's Script Doesn't Load

In the Network tab, right-click the request for `api.js` from `challenges.cloudflare.com`, choose **Block request URL**, and reload the page. The button is still enabled. Fill in the form and press Send, and the status shows “We couldn’t send your message. Check your connection, then press Send to try again.” Nothing was sent to the server, because there was no Turnstile token to send.

Now unblock the request in the Network tab's request blocking panel, without reloading, and press Send again. The controller loads Turnstile this time and the message goes through, still with everything the visitor typed.

### A Key Is Wrong

Change `TURNSTILE_SECRET_KEY` in `.env` to something wrong and send a message. The status shows “We can’t check messages right now. Please try again in a few minutes.” and the response is a 503. Cloudflare rejected the secret, which Protect reports as a `ConfigurationError`, and the module refuses to send anything until it's fixed.

Protect writes each error like this to its own log, so you don't need to log them yourself. Open the newest `protect-` file in `storage/logs`, such as `storage/logs/protect-2026-11-10.log`, and you'll find a line like this one:

```text
2026-11-10 10:15:24 [ERROR] Protection check challenge returned an error (provider-configuration) for site.contact.send, execution 0bc1a52d7ebc04f28549744bed068c4f.
```

The log only contains codes, never the visitor's message, email address or IP address. A wrong `AKISMET_API_KEY` looks the same, except the check is `classification` and Akismet's own explanation appears on the line before it. Put the right key back before you move on.

Under DDEV you may also see a warning that forwarding headers arrived while Craft's `trustedHosts` isn't narrowed. DDEV's router adds those headers to every request, so locally the warning is expected. On your live site, it means you need to [configure your proxy](../get-started/installation-setup.md#configure-your-proxy-first).

### A Service Is Down

You can't easily switch Cloudflare or Akismet off, but it helps to know what happens when either is unreachable. If Cloudflare can't verify a token, Protect retries once, which can hold the request for up to about 8 seconds. The visitor then sees the 503 message and can press Send again later with their message intact. If Akismet can't be reached, the message is held, so it lands in the review inbox and the visitor sees the usual thank-you. Both cases are logged.

## Before You Go Live

A few things are worth checking before the form goes on your live site.

Add your live hostname to the Turnstile widget if you haven't already, and make sure `PRIMARY_SITE_URL` and the two email addresses in the live `.env` are right. Turn dev mode off in production, which also turns off Akismet's test mode, so Akismet learns from real messages.

Configure `trustedHosts` if the site is behind a proxy or CDN, as described in [What You Need](#what-you-need). Without it, every visitor shares one rate limit, so once any three people have sent a message in the same ten minutes, everyone else is asked to wait.

If the site runs on more than one web server, rate counts and used preparations must live in a cache that every server shares, such as Redis, or each server keeps its own count. Flushing that cache resets every visitor's limits, so avoid clearing it as routine maintenance.

Finally, read the review inbox. A held message is only useful if someone looks at it, and Akismet is occasionally wrong in both directions.

If you'd rather build protection into a plugin that several sites can install, [Protect a Feedback Form](protect-a-feedback-form.md) walks through one, starting with a server-rendered form that works without JavaScript.
