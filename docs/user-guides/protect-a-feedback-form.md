# Protect a Feedback Form

This guide builds a small but complete Craft plugin called Feedback, which puts a “Send Us Feedback” form at the bottom of every article. Anyone can use it, including visitors who aren't signed in, and it's an ordinary form that Craft renders and the browser posts, with nothing to build with JavaScript. Because anyone can post it, it's also exactly the kind of form that bots find and fill with junk, so the plugin uses Protect to check every submission before anything is saved.

By the end you'll have a plugin with its own database table, a form template, a controller action that saves feedback, and a service that describes how the form is protected. The form gets a hidden honeypot field, a minimum time before it can be sent, protection against the same form being sent twice and a limit on how much feedback one visitor can send in ten minutes. A later stage adds a Cloudflare Turnstile challenge, which is the one part that needs a little JavaScript. Every file is shown in full, in the order you'd write it, so you can follow along from an empty folder, and the guide finishes by trying each way a submission can be refused and checking that nothing was saved.

## What You Need

Start with a Craft 5.10 or later site running PHP 8.2 or later. The examples use [DDEV](https://ddev.com), so commands start with `ddev`. If you run Craft another way, drop that prefix and run the rest from your project root.

The plugin attaches feedback to articles, so set up a channel section called **Articles** (`articles`) in the control panel, with entry URLs such as `articles/{slug}` and the template `articles/_entry`. Add two or three articles, such as “Planning the Spring Workshop” and “Volunteer Roster”. Your site also needs a layout template, such as `templates/_layout.twig`, with the usual `<html>`, `<head>` and `<body>` tags, which the article template extends.

You should be comfortable writing a little PHP and Twig. You don't need to know Protect yet, because each part is introduced as it comes up. The Turnstile stage near the end also needs a free Cloudflare account.

## How Protect Fits In

Protect isn't a plugin you install in the control panel. It's a Composer package that your plugin requires, and it has no settings screen, no database tables and nothing for site owners to configure. There's no start-up code to add either. The first time your plugin calls `Protect::getInstance()`, Protect sets itself up for the rest of the request.

Protect's job is to check one attempt at an action, such as “send feedback on this article”, and tell your plugin what it found. You describe the protection a form gets as a **plan**, which is a list of **checks**. Feedback's plan has four. A honeypot is a hidden field that people never see but bots tend to fill in. A minimum time catches scripts that post a form the instant it loads. Replay protection makes sure each copy of the form can only be sent once, and a rate limit caps how many attempts one visitor can make in a period of time.

A plan is used at two moments. When Craft renders the form, your plugin asks Protect to **prepare** it. Preparation produces a few hidden inputs, including a signed value that records when the form was rendered, which article it's for and which plan it was prepared with, and a honeypot field with a random name. When the form comes back, your plugin asks Protect to **check** the submission against the same plan. Protect verifies the signed value, runs each check and returns a **result**.

Protect never decides what happens next. A result says whether any check failed, which Protect calls an **adverse finding**, such as a filled honeypot, and whether any check couldn't run at all, which is an **error**, such as Craft's cache being unavailable. Your plugin turns that into a response for the visitor. Feedback's controller does this in a few lines, and the guide explains each choice when it gets there.

Replay protection and rate limits need to remember what they've seen, and they keep that in Craft's cache rather than in database tables. That's why Protect needs no tables of its own, and it's also why a busy site shouldn't clear its data cache as routine maintenance, as [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex) explains.

## Create the Plugin Package

Craft plugins are Composer packages, so start with a `plugins/feedback` folder in your project and add a `composer.json` to it. The `require` section lists Protect, which means anyone who installs Feedback gets Protect too.

```json
{
    "name": "acme/craft-feedback",
    "description": "Visitors send feedback on articles.",
    "type": "craft-plugin",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "craftcms/cms": "^5.10.0",
        "verbb/protect": "^1.0.0"
    },
    "autoload": {
        "psr-4": {
            "acme\\feedback\\": "src/"
        }
    },
    "extra": {
        "handle": "feedback",
        "name": "Feedback",
        "developer": "Acme",
        "class": "acme\\feedback\\Feedback"
    }
}
```

The `extra` section is what makes this a Craft plugin. It gives Craft the plugin's handle, its name and the class that runs it.

While you're developing the plugin, tell your project where to find it with a path repository, then require it. Run these from your project root:

```shell
ddev composer config repositories.feedback '{"type":"path","url":"plugins/feedback","options":{"symlink":true}}'
ddev composer require acme/craft-feedback
```

Composer links `vendor/acme/craft-feedback` to your `plugins/feedback` folder, so your edits take effect straight away, and it installs Protect alongside. Don't install the plugin in Craft yet, because it has no code. You'll do that once the files below are in place.

## Write the Plugin Class

Create `plugins/feedback/src/Feedback.php`. This is the class named in `composer.json`, and Craft runs its `init()` on every request.

```php
<?php
namespace acme\feedback;

use acme\feedback\services\Protection;
use acme\feedback\variables\FeedbackVariable;

use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;

use yii\base\Event;

class Feedback extends Plugin
{
    public static function config(): array
    {
        return [
            'components' => [
                'protection' => Protection::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['feedback'] = __DIR__ . '/templates';
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('feedback', FeedbackVariable::class);
        });
    }

    public function getProtection(): Protection
    {
        return $this->get('protection');
    }
}
```

`config()` registers the plugin's one service, `protection`, which you'll write shortly and reach through `getProtection()`. The template roots event lets your site templates include the plugin's templates as `feedback/...`, and the variable event makes `craft.feedback` available in Twig.

Notice that nothing here starts Protect. The service calls `Protect::getInstance()` when it first needs it, and that's all the setup Protect requires. If another plugin on the same site also uses Protect, both get the same instance.

## Store Feedback in Your Own Table

Each piece of feedback is a row in a table that the plugin creates. It records which article the feedback is about, which site it was sent on, the message and an optional email address for a reply. It doesn't record the visitor's IP address, because the plugin has no use for it. Protect needs the address for its rate limit, but it only ever stores a one-way hash of it in the cache, and only until the limit's window ends.

Create the record class in `plugins/feedback/src/records/Submission.php`:

```php
<?php
namespace acme\feedback\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $entryId
 * @property int $siteId
 * @property string $message
 * @property string|null $email
 */
class Submission extends ActiveRecord
{
    public const MAX_LENGTH = 2000;

    public static function tableName(): string
    {
        return '{{%feedback_submissions}}';
    }

    public function rules(): array
    {
        return [
            [['message'], 'required', 'message' => Craft::t('feedback', 'Write your feedback before sending it.')],
            [['message'], 'string', 'max' => self::MAX_LENGTH],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
        ];
    }
}
```

The record does two jobs. It saves rows to the table, and its `rules()` describe what valid feedback looks like, so the controller can validate a submission before saving it. A message is required and can be up to 2,000 characters, and the email address is optional but has to look like one when it's given.

Then create the install migration in `plugins/feedback/src/migrations/Install.php`. Craft runs it when the plugin is installed and reverses it with `safeDown()` when it's uninstalled.

```php
<?php
namespace acme\feedback\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%feedback_submissions}}', [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'message' => $this->text()->notNull(),
            'email' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%feedback_submissions}}', ['entryId', 'dateCreated']);

        $this->addForeignKey(null, '{{%feedback_submissions}}', ['entryId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%feedback_submissions}}', ['siteId'], Table::SITES, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%feedback_submissions}}');

        return true;
    }
}
```

The foreign keys tidy up after you, so deleting an article or a site deletes its feedback. Craft fills in `dateCreated`, `dateUpdated` and `uid` whenever a record is saved.

## Describe the Protection in One Place

The form needs the same plan twice, once to prepare it when Craft renders the page and once to check what comes back. If those two plans differed, every submission would be refused, so the plan lives in one service that both moments use. Create `plugins/feedback/src/services/Protection.php`:

```php
<?php
namespace acme\feedback\services;

use craft\base\Component;
use craft\elements\Entry;
use craft\web\Request;

use verbb\protect\Protect;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\errors\PreparationException;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionPreparation;
use verbb\protect\models\ProtectionResult;
use verbb\protect\models\RateLimitRule;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

class Protection extends Component
{
    // What the visitor is doing. A form prepared for one action can't be sent to another.
    public const ACTION = 'feedback.send';

    // The section whose entries take feedback.
    public const SECTION = 'articles';

    private ?ProtectionPlan $_plan = null;

    /**
     * The one plan used to prepare the form and to check what comes back.
     */
    public function getPlan(): ProtectionPlan
    {
        return $this->_plan ??= (new ProtectionPlan(handle: 'feedback'))
            ->add(new Honeypot())
            ->add(new MinimumTime(['seconds' => 3]))
            ->add(new ReplayProtection())
            ->add(new RateLimit([
                'rules' => [
                    new RateLimitRule(['handle' => 'per-visitor', 'limit' => 5, 'window' => 600]),
                ],
            ]));
    }

    public function getArticle(int $entryId): ?Entry
    {
        if ($entryId < 1) {
            return null;
        }

        return Entry::find()
            ->id($entryId)
            ->section(self::SECTION)
            ->one();
    }

    public function prepare(Entry $article): ProtectionPreparation
    {
        return Protect::getInstance()->prepare(new ProtectionContext(
            action: self::ACTION,
            subject: SubjectReference::fromElement($article),
        ), $this->getPlan());
    }

    public function check(Entry $article, Request $request): ProtectionResult
    {
        try {
            $evidence = ProtectionEvidence::fromRequest($request);
        } catch (PreparationException) {
            // Malformed evidence is checked as no evidence at all, which fails like a stale form.
            $evidence = new ProtectionEvidence();
        }

        return Protect::getInstance()->check(new ProtectionContext(
            action: self::ACTION,
            subject: SubjectReference::fromElement($article),
            request: RequestSignals::fromRequest($request),
            evidence: $evidence,
        ), $this->getPlan());
    }
}
```

The next three sections walk through the plan, preparing the form and checking a submission.

### The Plan

`getPlan()` builds a `ProtectionPlan` with the handle `feedback` and adds four checks to it. Each check is configured with an array of settings, in the same way as other Craft components.

`Honeypot` needs no settings. When the form is prepared, it adds a text field with a random name inside a `<div>` that has the `hidden` attribute, so browsers never show it to people, and `aria-hidden`, so screen readers skip it too. A bot that fills in every field it finds fills this one, and the check fails with the code `honeypot-populated`.

`MinimumTime` with `'seconds' => 3` refuses a form sent less than three seconds after it was prepared, with the code `minimum-time`. People take longer than that to read an article and write a sentence, while a script often posts a form the moment it has loaded it. Three seconds is also the default, and you can choose anything from 0 to 300.

`ReplayProtection` makes each copy of the form single use. Every preparation carries a random value, and the first time a form is checked, Protect claims that value in Craft's cache. If the same form arrives again, whether it's a bot replaying a captured request or a visitor sending a copy they saved, the claim already exists and the check fails with `replay-detected`. This is also why every page view needs a freshly prepared form, which comes up again when you write the template.

`RateLimit` takes one or more rules. The rule here, `per-visitor`, allows five attempts in each 600-second window. By default a rule counts attempts by the visitor's IP address, separately for each action and each site, but not separately for each article, so five pieces of feedback in ten minutes is the limit across the whole site rather than per article. The sixth attempt fails with `rate-limit-exceeded`, and the result says how many seconds remain until the window ends. Windows are fixed to the clock, such as 10:00 to 10:10, which keeps them cheap to count but means a visitor could send five in the minute before 10:10 and five more in the minute after. Rate limits depend on Protect seeing each visitor's real address, which [Make Sure Protect Sees the Real Visitor Address](#make-sure-protect-sees-the-real-visitor-address) covers before you go any further.

Protect runs the checks in stages rather than strictly in the order you add them. Checks that only look at the form (the honeypot and minimum time) run first. Checks that record something (replay protection and the rate limit) run next, in the order the plan adds them, and a challenge such as Turnstile runs last. By default Protect stops at the first check that fails. That has two useful effects for Feedback. A bot caught by the honeypot never uses up a rate-limit attempt, and a replayed form is refused before it's counted.

The plan's handle and settings are part of what a prepared form is signed against, so changing the plan, such as raising the limit to ten, makes any form that's already open in someone's browser invalid. The next time it's sent it's refused once, and the page that comes back carries a form prepared with the new plan. A prepared form also expires after an hour, which is the plan's default lifetime.

`??=` builds the plan the first time it's asked for and keeps it for the rest of the request, so the template and the controller share one plan object.

### Preparing a Form

`prepare()` asks Protect to prepare the form for one article. The `ProtectionContext` describes the attempt. Its `action`, `feedback.send`, names what the visitor is doing, and a form prepared for one action can't be sent to another. Its `subject` is the article, built with `SubjectReference::fromElement()`, so the signed value records the article's ID and site. If someone takes a form from one article and sends it with another article's ID, Protect refuses it with `preparation-mismatch`.

The result is a `ProtectionPreparation`. Its `renderHiddenInputs()` method outputs the hidden inputs and honeypot field, ready to drop into the form, which the template does in a moment.

### Checking a Submission

`check()` runs when the form comes back. `ProtectionEvidence::fromRequest()` reads the hidden inputs from the posted form, which all arrive under the name `__protect`. If that value has been tampered with into something that isn't the expected shape, it throws a `PreparationException`. The service catches it and checks an empty set of evidence instead, which fails the same way as a form with no Protect fields at all, with the code `preparation-invalid`. That way a tampered request gets the same polite refusal as any other, rather than an error page.

`RequestSignals::fromRequest()` collects the visitor's IP address for the rate limit, and nothing else unless you ask for it. The action and subject are built exactly as they were for preparation, from the article the plugin has looked up itself, and never from anything the visitor sent about who they are.

`getArticle()` is how the plugin finds that article. It only returns a live entry in the Articles section on the current site, so feedback can't be posted against a draft, a disabled entry or an entry in another section.

## Make Sure Protect Sees the Real Visitor Address

The rate limit counts attempts by IP address, so it only works if each visitor has their own. Many sites sit behind a load balancer, reverse proxy or CDN such as Cloudflare. On those sites every request reaches Craft from the proxy's address, and the visitor's own address arrives in a header such as `X-Forwarded-For`. If Protect used the proxy's address, every visitor would share one rate limit, and five pieces of feedback from anyone would block everyone for the rest of the window.

Protect works out the visitor's address from Craft's own `trustedHosts` and `ipHeaders` settings, so there's nothing Protect-specific to configure. It doesn't trust forwarding headers by default, because a visitor can send any header they like. With `trustedHosts` left at its default of `any`, Protect uses the address of the connection itself. If forwarding headers arrive anyway, it writes a warning to its log in `storage/logs`, at most once a day, saying that `trustedHosts` isn't narrowed to your proxies.

To fix that, list your proxies' address ranges in `config/general.php`. For a site behind a load balancer on a private network, that might be:

```php
<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->trustedHosts(['10.0.0.0/8']);
```

When a request comes from one of those ranges, Protect reads `X-Forwarded-For` from the right, skipping your own proxies, and uses the first address that isn't one of them. A request from anywhere else uses the connection's address, whatever headers it sends. List the ranges in CIDR form, such as `10.0.0.0/8`, because Protect can't match a hostname. A site behind Cloudflare lists Cloudflare's published IP ranges in `trustedHosts` and adds `->ipHeaders(['CF-Connecting-IP'])`, so Protect reads the header that Cloudflare sets. [Configure Your Proxy First](../get-started/installation-setup.md#configure-your-proxy-first) covers the other headers and edge cases.

On your local DDEV site you're the only visitor, so a shared address doesn't get in the way of following this guide. Set `trustedHosts` correctly before the plugin goes live, and if your production site has more than one web server, read [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex) as well, because every server needs to see the same rate counters.

If Protect can't find any valid address for a request, it can't apply a rate limit keyed on one, so the rate limit returns an error with the code `rate-identity-missing` rather than letting the attempt through uncounted. The controller treats that the same as any other error.

## Add the Send Action

Visitors send feedback through a controller action. Create `plugins/feedback/src/controllers/FeedbackController.php`:

```php
<?php
namespace acme\feedback\controllers;

use acme\feedback\Feedback;
use acme\feedback\records\Submission;

use Craft;
use craft\web\Controller;

use verbb\protect\models\Finding;

use yii\web\NotFoundHttpException;
use yii\web\Response;

class FeedbackController extends Controller
{
    // Guests can send feedback, so the action doesn't require a signed-in user.
    protected array|bool|int $allowAnonymous = ['send'];

    public function actionSend(): ?Response
    {
        $this->requirePostRequest();

        $protection = Feedback::getInstance()->getProtection();
        $article = $protection->getArticle((int)$this->request->getRequiredBodyParam('entryId'));

        if (!$article) {
            throw new NotFoundHttpException('Article not found.');
        }

        $submission = new Submission();
        $submission->entryId = $article->id;
        $submission->siteId = $article->siteId;
        $submission->message = trim((string)$this->request->getBodyParam('message'));
        $submission->email = trim((string)$this->request->getBodyParam('email')) ?: null;

        // Ordinary validation comes first, so a typo doesn't use up one of the visitor's attempts.
        if (!$submission->validate()) {
            return $this->refuse($submission, Craft::t('feedback', 'Check your feedback and try again.'));
        }

        // The one protection check, immediately before saving.
        $result = $protection->check($article, $this->request);

        if ($result->hasAdverseFindings()) {
            // Machine codes only, such as `minimum-time`, never the visitor's text or address.
            $codes = array_map(fn(Finding $finding) => $finding->code, iterator_to_array($result->findings()));
            Craft::info('Feedback refused (' . implode(', ', $codes) . ').', 'feedback');
        }

        if (($retryAfter = $result->retryAfter()) !== null) {
            $this->response->getHeaders()->set('Retry-After', (string)$retryAfter);

            return $this->refuse($submission, Craft::t('feedback', 'You’ve sent a lot of feedback in a short time. Please wait {minutes, plural, =1{a minute} other{# minutes}} and send it again.', [
                'minutes' => (int)ceil($retryAfter / 60),
            ]), 429);
        }

        if ($result->hasErrors()) {
            return $this->refuse($submission, Craft::t('feedback', 'We couldn’t check your feedback just now. Please try again in a few minutes.'), 503);
        }

        if ($result->hasAdverseFindings()) {
            return $this->refuse($submission, Craft::t('feedback', 'We couldn’t accept that. Please check your feedback and send it again.'), 400);
        }

        $submission->save(false);

        return $this->asSuccess(Craft::t('feedback', 'Thanks for your feedback.'));
    }

    /**
     * Shows the article again with an error, and with the visitor's text still in the form.
     */
    private function refuse(Submission $submission, string $message, ?int $status = null): ?Response
    {
        $response = $this->asFailure($message, routeParams: ['submission' => $submission]);

        if ($status !== null) {
            $this->response->setStatusCode($status);
        }

        return $response;
    }
}
```

Craft controller actions normally require a signed-in user, and `$allowAnonymous` lets guests use `send`. Craft still checks the form's CSRF token automatically, for guests as well as members, and `requirePostRequest()` refuses anything that isn't a form post. The action looks the article up through `getArticle()`, so feedback can only be sent for a live article.

The order of what follows matters. The submission is validated first, because a visitor who leaves the message empty or mistypes their email address hasn't done anything suspicious, and checking protection first would use up one of their rate-limit attempts and their form's single use for nothing. Only once the feedback is valid does the action call `check()`, and it calls it exactly once, immediately before saving. Don't move the check into the record's validation rules or into a database transaction that might be retried, because each check records a replay claim and a rate-limit attempt, and running it twice for one submission would refuse the second run as a replay.

When the check passes, `save(false)` saves the row without validating it again, and `asSuccess()` sets a “Thanks for your feedback.” message and redirects the visitor back to the article.

### Turning Results into Responses

A `ProtectionResult` has a few methods for deciding what to do, so the controller never has to read message text.

`retryAfter()` returns the number of seconds until every exceeded rate limit has cleared, or `null` if no limit was exceeded. When it has a value, the controller responds with HTTP status 429 (Too Many Requests) and a `Retry-After` header holding those seconds, which is the standard way to tell browsers and well-behaved clients when to try again. The visitor sees a message that rounds the wait up to whole minutes.

`hasErrors()` is true when a check couldn't run, such as when Craft's cache is unavailable or, with Turnstile later, when Cloudflare can't be reached. The controller responds with status 503 (Service Unavailable) and asks the visitor to try again in a few minutes. That choice refuses the feedback whenever Protect can't check it, which suits a form that guests can post. A plugin could instead let submissions through during an outage, but it should never do that for a configuration error such as a wrong secret key, because that won't fix itself. [Results and Findings](../feature-tour/results-and-findings.md) explains the kinds of error. Protect writes every error to its own log once, with its code, so you don't need to log errors yourself.

`hasAdverseFindings()` is true when any check failed, which covers the honeypot, minimum time, replay protection, a stale or mismatched form and, later, a failed challenge. The controller responds with status 400 and a deliberately general message. It doesn't tell the visitor which check failed, because that would tell a bot what to change. A person who sees it has usually sent the form too quickly or sent an old copy, and sending it again from the page they're looking at works.

The `Craft::info()` line writes the codes of any failed checks to Craft's `web` log in `storage/logs`, such as `Feedback refused (minimum-time).`, while `devMode` is on. That's useful while you test the plugin. The line itself only names the checks that failed, but Craft adds its usual request context after it, including the posted form fields, so keep this logging to development.

### Keeping the Visitor's Text

Every refusal goes through `refuse()`, which calls Craft's `asFailure()` with the unsaved `Submission` as a route parameter. For a normal form post, `asFailure()` sets an error message and lets Craft carry on rendering the page the form was posted to, which is the article. The template then receives the submission as a `submission` variable and puts the visitor's message and email address back into the form, along with any validation errors.

Because the article page is rendered again, the template also prepares a brand new form for it, with a new signed value and a new honeypot name. The visitor doesn't need to reload anything. They can read the message, wait if they were asked to, and send the same text again. If the attempt got as far as replay protection, the form they sent has been used up, but they're no longer holding it.

`refuse()` sets the status code after calling `asFailure()`, because `asFailure()` sets its own status of 400 when a request asks for JSON, and the status chosen here should win.

## Add a Template Variable

The template needs to prepare the form, so give it a way to reach the service. Create `plugins/feedback/src/variables/FeedbackVariable.php`:

```php
<?php
namespace acme\feedback\variables;

use acme\feedback\Feedback;

use craft\elements\Entry;

use verbb\protect\models\ProtectionPreparation;

class FeedbackVariable
{
    /**
     * A fresh, single-use preparation for this visitor, so never cache the page it's on in a shared cache.
     */
    public function prepare(Entry $article): ProtectionPreparation
    {
        return Feedback::getInstance()->getProtection()->prepare($article);
    }
}
```

This makes `craft.feedback.prepare(entry)` available in any site template.

## Write the Feedback Form

Create `plugins/feedback/src/templates/_form.twig`. It shows any message from the last attempt, and the form itself.

```twig
{# Include on an article page with {% include 'feedback/_form' with { entry } %} #}
{% set preparation = craft.feedback.prepare(entry) %}
{% set submission = submission ?? null %}
{% set success = craft.app.session.getFlash('success') %}
{% set error = craft.app.session.getFlash('error') %}

<section id="feedback" class="feedback">
    <h2>{{ 'Send Us Feedback'|t('feedback') }}</h2>
    <p>{{ 'Spotted a mistake, or something we could explain better? Let us know.'|t('feedback') }}</p>

    {% if success %}
        <p class="feedback-success" role="status">{{ success }}</p>
    {% endif %}

    {% if error %}
        <p class="feedback-error" role="alert">{{ error }}</p>
    {% endif %}

    <form method="post" action="{{ entry.url }}#feedback">
        {{ csrfInput() }}
        {{ actionInput('feedback/feedback/send') }}
        {{ redirectInput(entry.url ~ '#feedback') }}
        {{ hiddenInput('entryId', entry.id) }}
        {{ preparation.renderHiddenInputs() }}

        <label for="feedback-message">{{ 'Your feedback'|t('feedback') }}</label>
        <textarea id="feedback-message" name="message" rows="5" maxlength="2000" required>{{ submission.message ?? '' }}</textarea>

        {% if submission and submission.hasErrors('message') %}
            <p class="feedback-field-error">{{ submission.getFirstError('message') }}</p>
        {% endif %}

        <label for="feedback-email">{{ 'Email (optional, if you’d like a reply)'|t('feedback') }}</label>
        <input id="feedback-email" type="email" name="email" value="{{ submission.email ?? '' }}">

        {% if submission and submission.hasErrors('email') %}
            <p class="feedback-field-error">{{ submission.getFirstError('email') }}</p>
        {% endif %}

        <button type="submit">{{ 'Send Feedback'|t('feedback') }}</button>
    </form>
</section>
```

The first line prepares the form for the article being viewed. Because it runs every time the template renders, each page view gets its own preparation, including the page Craft renders after a refusal.

The form posts back to the article's own URL, and `actionInput()` routes the post to the controller's `send` action. That's what lets a refusal show the article again with the visitor's text in place. `#feedback` on the end of the URL brings the visitor back down to the form instead of the top of the article. `redirectInput()` tells Craft where to go after a successful submission, and `entryId` names the article. `csrfInput()` is Craft's usual CSRF field, and Protect doesn't replace it, because the two protect against different things. The CSRF token stops another site from posting the form on a visitor's behalf, while Protect checks whether the attempt itself looks like a person sending feedback.

`preparation.renderHiddenInputs()` outputs Protect's fields. You'll see two hidden inputs, `__protect[version]` and `__protect[preparation]`, which holds the signed value, followed by the honeypot's text field inside a hidden `<div>`. The honeypot is hidden by the `hidden` attribute, so it needs no stylesheet, and it has `tabindex="-1"` and `autocomplete="off"`, so keyboard users don't land on it and browsers don't autofill it.

The textarea and email field use `submission.message ?? ''` and `submission.email ?? ''`, which are empty on a normal page view and hold the visitor's text after a refusal. After a successful submission, Craft redirects to the article, and `success` holds the thank-you message that `asSuccess()` stored in the session.

The page this form is on can't be stored in a cache that other visitors share. Its CSRF token and its prepared form belong to one visitor, and a cached copy would hand everyone the same single-use form, so only the first person to send it would get through. Don't wrap the include in Craft's `{% cache %}` tag, and if the site uses a full-page or static cache, exclude article pages or load the form separately. [Prepare the Form](../developers/integrating-an-action.md#prepare-the-form) describes fetching a fresh preparation for a cached page.

## Install the Plugin and Show the Form

With every file in place, install the plugin. Craft runs the install migration and creates the `feedback_submissions` table.

```shell
ddev craft plugin/install feedback
```

Now include the form in your article template, `templates/articles/_entry.twig`. This site's article template extends its own `_layout.twig`. Yours can extend whatever layout your site uses.

```twig
{% extends '_layout' %}

{% block content %}
    <h1>{{ entry.title }}</h1>

    {{ entry.body }}

    {% include 'feedback/_form' with { entry } %}
{% endblock %}
```

Passing `entry` hands the form the article being viewed, which is the article the feedback is about. Use whatever fields your articles have in place of `entry.body`.

## Send Your First Feedback

Open an article in a private browser window, so you're a guest. Below the article you'll see **Send Us Feedback**, a short introduction, a box for your feedback, an email field and a **Send Feedback** button.

If you view the page source, you'll find Protect's fields inside the form. Reload the page and look again, and the value of `__protect[preparation]` and the name of the honeypot field have both changed, because each page view is prepared separately.

Type some feedback, such as “The second diagram is missing its labels.”, leave the email field empty and choose **Send Feedback**. The page reloads at the form, showing “Thanks for your feedback.” above an empty form.

To check the feedback was saved, ask Craft to count the rows from your project root:

```shell
ddev craft exec '(int)acme\feedback\records\Submission::find()->count()'
```

Craft prints `= 1`, the result of the expression. If it prints `= 0` and the page showed an error instead, the message tells you which branch of the controller refused it. “Check your feedback and try again.” means validation failed, so look for the error under the field. “We couldn’t accept that.” usually means the form was sent within three seconds of loading, so wait a moment and send it again.

The form is now fully protected against the most common kinds of automated junk, with no JavaScript at all. The next stage adds a challenge, and the guide then tests every way a submission can be refused. If you'd rather keep the form free of JavaScript, you can skip the Turnstile stage and run the same tests, apart from the parts that mention Turnstile.

## Add a Turnstile Challenge

A honeypot and a minimum time stop simple bots, but a determined script can leave the honeypot empty and wait three seconds. A challenge asks a third party to check that a real browser, used by a person, is sending the form. [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) does this mostly without bothering the visitor, by checking the browser in the background and only showing an interactive challenge when it isn't sure.

A challenge has to run in the visitor's browser, so this stage needs JavaScript. Protect's browser script handles the Turnstile widget and adds its token to the form, and it comes with Protect's Composer package already built, so there's nothing to install with npm and nothing to build.

Adding a challenge has one consequence worth deciding on deliberately. A visitor with JavaScript turned off can't complete it, so once Turnstile is in the plan, the form can't be sent without JavaScript. Protect never lets a submission skip a challenge because the browser didn't run it, since a bot could make the same claim. The template tells those visitors why.

### Create a Turnstile Widget

In the Cloudflare dashboard, open Turnstile and add a widget. Give it a name such as “Article Feedback” and add the hostnames the form will be sent from, which for this guide is your DDEV site's hostname, such as `acme.ddev.site`. Add your production hostname when you're ready to go live, or create a separate widget for it. Managed mode suits this form. Cloudflare then shows you a site key, which is public and goes into the page, and a secret key, which stays on the server.

Don't use Cloudflare's published test keys for this. Their verification response has no action and reports the hostname `example.com`, so Protect can't confirm the token belongs to this form and treats the response as invalid. Every submission would then get the “We couldn’t check your feedback just now” message. A widget created for your own hostname behaves exactly as it will in production.

Add the keys and hostnames to your project's `.env` file:

```shell
FEEDBACK_TURNSTILE_SITE_KEY="your-site-key"
FEEDBACK_TURNSTILE_SECRET_KEY="your-secret-key"
FEEDBACK_TURNSTILE_HOSTNAMES="acme.ddev.site"
```

`FEEDBACK_TURNSTILE_HOSTNAMES` is a comma-separated list, such as `acme.ddev.site,www.acme.com`, for a site with more than one hostname.

### Add the Challenge to the Plan

Replace the contents of `plugins/feedback/src/services/Protection.php` with this version, which adds a `Challenge` check to the end of the plan and a small method that reads the hostnames:

```php
<?php
namespace acme\feedback\services;

use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\App;
use craft\web\Request;

use verbb\protect\Protect;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\errors\PreparationException;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionPreparation;
use verbb\protect\models\ProtectionResult;
use verbb\protect\models\RateLimitRule;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

class Protection extends Component
{
    // What the visitor is doing. A form prepared for one action can't be sent to another.
    public const ACTION = 'feedback.send';

    // The section whose entries take feedback.
    public const SECTION = 'articles';

    private ?ProtectionPlan $_plan = null;

    /**
     * The one plan used to prepare the form and to check what comes back.
     */
    public function getPlan(): ProtectionPlan
    {
        return $this->_plan ??= (new ProtectionPlan(handle: 'feedback'))
            ->add(new Honeypot())
            ->add(new MinimumTime(['seconds' => 3]))
            ->add(new ReplayProtection())
            ->add(new RateLimit([
                'rules' => [
                    new RateLimitRule(['handle' => 'per-visitor', 'limit' => 5, 'window' => 600]),
                ],
            ]))
            ->add(new Challenge([
                'provider' => new Turnstile([
                    'siteKey' => '$FEEDBACK_TURNSTILE_SITE_KEY',
                    'secretKey' => '$FEEDBACK_TURNSTILE_SECRET_KEY',
                    'expectedAction' => 'feedback',
                    'hostnames' => $this->getTurnstileHostnames(),
                ]),
            ]));
    }

    /**
     * The hostnames a Turnstile token may come from, such as "acme.ddev.site,www.acme.com".
     */
    public function getTurnstileHostnames(): array
    {
        $hostnames = explode(',', (string)App::env('FEEDBACK_TURNSTILE_HOSTNAMES'));

        return array_values(array_filter(array_map('trim', $hostnames)));
    }

    public function getArticle(int $entryId): ?Entry
    {
        if ($entryId < 1) {
            return null;
        }

        return Entry::find()
            ->id($entryId)
            ->section(self::SECTION)
            ->one();
    }

    public function prepare(Entry $article): ProtectionPreparation
    {
        return Protect::getInstance()->prepare(new ProtectionContext(
            action: self::ACTION,
            subject: SubjectReference::fromElement($article),
        ), $this->getPlan());
    }

    public function check(Entry $article, Request $request): ProtectionResult
    {
        try {
            $evidence = ProtectionEvidence::fromRequest($request);
        } catch (PreparationException) {
            // Malformed evidence is checked as no evidence at all, which fails like a stale form.
            $evidence = new ProtectionEvidence();
        }

        return Protect::getInstance()->check(new ProtectionContext(
            action: self::ACTION,
            subject: SubjectReference::fromElement($article),
            request: RequestSignals::fromRequest($request),
            evidence: $evidence,
        ), $this->getPlan());
    }
}
```

A `Challenge` check takes a provider, here `Turnstile`. The keys are written as `'$FEEDBACK_TURNSTILE_SITE_KEY'` in single quotes, so PHP leaves the `$` alone and Protect reads the value from the environment when it needs it. The secret key is only ever used on the server, when Protect sends the visitor's token to Cloudflare to verify it, and it never appears in the page.

`expectedAction` is a short label, `feedback`, that the widget attaches to every token it issues. When Cloudflare verifies a token, it reports the action and the hostname the token came from, and Protect refuses the token with `challenge-context-mismatch` unless the action is `feedback` and the hostname is one in your list. That stops a token earned on some other form, or some other site, being used here. The hostnames aren't read from an environment expression like the keys, so `getTurnstileHostnames()` reads the comma-separated list from `.env` with Craft's `App::env()` and trims each name.

The challenge runs after the honeypot, minimum time, replay protection and rate limit, because challenges always run in a later stage than checks that only look at the form or record something. A form that's refused by any of those is never sent to Cloudflare at all.

Changing the plan makes forms that are already open invalid, as described earlier, so reload any article you have open before you try the form again.

### Load Protect's Browser Script

Protect's Composer package includes its browser script already built, so the plugin doesn't need npm, a bundler or any JavaScript of its own. The template only has to swap one call. In place of `renderHiddenInputs()`, it calls `renderBrowserInputs()`, which outputs the same hidden inputs plus an empty, hidden element that holds the form's **manifest**, and tells Craft to load Protect's script on the page.

The manifest is the public half of the preparation. It names the Turnstile site key and the `feedback` action, and it records when the form was prepared and the earliest moment it can be sent. It never holds the secret key.

When the page loads, the script finds each form containing a manifest and mounts protection on it. Mounting reuses the hidden inputs already in the form, adds one more empty hidden input for the Turnstile token, and renders the Turnstile widget without running it, so a page that's read but never sent costs nothing.

When the visitor chooses **Send Feedback**, the script holds the submission for a moment. If the form is less than three seconds old, it waits until it isn't, so a quick visitor sees a short pause rather than a refusal. Then it runs the Turnstile challenge and puts the token Cloudflare issues into the token field. The widget uses Turnstile's interaction-only appearance, so most visitors never see it, and if Cloudflare does want the visitor to interact, the widget appears at the bottom of the form. Once the token is in place, the script sends the form as an ordinary page request, using the same button the visitor pressed, and everything from there is the same as before. While a submission is held, the form has an `aria-busy="true"` attribute, which screen readers announce and which you can style. Pressing the button again during that moment doesn't send the form twice.

If the challenge can't complete, such as when Cloudflare can't be reached or doesn't recognise the hostname, the script sends the form anyway, without a token. That might seem backwards, but it means the server makes the decision, the same as for every other refusal. Protect refuses the submission with `challenge-token-missing`, the controller renders the article again with the visitor's text still in the box and a freshly prepared form, and the visitor can try again. A missing token can never get a submission past the challenge, because the server checks for one.

### Update the Template

Replace `plugins/feedback/src/templates/_form.twig` with this version:

```twig
{# Include on an article page with {% include 'feedback/_form' with { entry } %} #}
{% set preparation = craft.feedback.prepare(entry) %}
{% set submission = submission ?? null %}
{% set success = craft.app.session.getFlash('success') %}
{% set error = craft.app.session.getFlash('error') %}

<section id="feedback" class="feedback">
    <h2>{{ 'Send Us Feedback'|t('feedback') }}</h2>
    <p>{{ 'Spotted a mistake, or something we could explain better? Let us know.'|t('feedback') }}</p>

    {% if success %}
        <p class="feedback-success" role="status">{{ success }}</p>
    {% endif %}

    {% if error %}
        <p class="feedback-error" role="alert">{{ error }}</p>
    {% endif %}

    <form method="post" action="{{ entry.url }}#feedback">
        {{ csrfInput() }}
        {{ actionInput('feedback/feedback/send') }}
        {{ redirectInput(entry.url ~ '#feedback') }}
        {{ hiddenInput('entryId', entry.id) }}
        {{ preparation.renderBrowserInputs() }}

        <label for="feedback-message">{{ 'Your feedback'|t('feedback') }}</label>
        <textarea id="feedback-message" name="message" rows="5" maxlength="2000" required>{{ submission.message ?? '' }}</textarea>

        {% if submission and submission.hasErrors('message') %}
            <p class="feedback-field-error">{{ submission.getFirstError('message') }}</p>
        {% endif %}

        <label for="feedback-email">{{ 'Email (optional, if you’d like a reply)'|t('feedback') }}</label>
        <input id="feedback-email" type="email" name="email" value="{{ submission.email ?? '' }}">

        {% if submission and submission.hasErrors('email') %}
            <p class="feedback-field-error">{{ submission.getFirstError('email') }}</p>
        {% endif %}

        <noscript>
            <p class="feedback-error">{{ 'This form needs JavaScript to check that you’re a person.'|t('feedback') }}</p>
        </noscript>

        <button type="submit">{{ 'Send Feedback'|t('feedback') }}</button>
    </form>
</section>
```

Two things have changed. `renderBrowserInputs()` replaces `renderHiddenInputs()`, and a `<noscript>` message near the button tells visitors without JavaScript why the form won't work for them. Craft places Protect's script tag just before your layout's closing `</body>` tag, which it finds automatically, so the layout needs no changes.

To give visitors a sign that the form is working while it's held, add a rule like this to your site's stylesheet:

```css
.feedback form[aria-busy="true"] button {
    opacity: 0.6;
    cursor: progress;
}
```

If the site sends a Content Security Policy, the browser has to be allowed to load Protect's script from your own site and Turnstile's script and frame from Cloudflare. Protect adds no inline scripts or styles. [Content Security Policy](../developers/browser-package.md#content-security-policy) lists what's needed, including how to pass a nonce.

### Try the Challenge

Reload an article in your private window. The form looks the same as before, because the Turnstile widget stays invisible until it's needed. Type some feedback and choose **Send Feedback**. The form waits a moment while Turnstile checks the browser, then the page reloads with “Thanks for your feedback.”, and `ddev craft exec '(int)acme\feedback\records\Submission::find()->count()'` shows one more than before.

To see the challenge's part in that, open your browser's developer tools before sending, and look at the posted form data for the request in the Network panel. Alongside the fields from before, there's `__protect[challenges][turnstile]` holding the token Cloudflare issued.

If something isn't set up correctly, the symptom points to the cause:

- An `InvalidPlanException` saying the site key is empty, or that the hostnames can't be blank, means `.env` is missing `FEEDBACK_TURNSTILE_SITE_KEY` or `FEEDBACK_TURNSTILE_HOSTNAMES`. Protect checks the plan when the form is prepared, so the error appears when the article page renders.
- “We couldn’t accept that” after sending, with `challenge-token-missing` in Craft's `web` log, means the challenge couldn't complete in the browser, so the form was sent without a token. The usual cause is a hostname that isn't on the widget in Cloudflare's dashboard. Turnstile usually reports its error code in the browser's console.
- “We couldn’t check your feedback just now” after sending means Protect couldn't verify the token with Cloudflare. A wrong secret key gives the code `provider-configuration`, and Cloudflare's test keys give `invalid-provider-response`. Protect writes the code to its own log in `storage/logs`.
- “We couldn’t accept that” after sending, with `challenge-context-mismatch` in Craft's `web` log, means Cloudflare verified the token but its hostname isn't in `FEEDBACK_TURNSTILE_HOSTNAMES`.

## Test Every Outcome

The form works for a visitor sending feedback normally. This section tries each way a submission can be refused, so you can see what a visitor would see and confirm that nothing is saved. Keep a terminal open at your project root to count the saved rows between tests:

```shell
ddev craft exec '(int)acme\feedback\records\Submission::find()->count()'
```

With `devMode` on, each refusal also writes a line to Craft's `web` log in `storage/logs`, such as `Feedback refused (minimum-time).`, naming the check that refused it. The visitor never sees which check that was.

The rate limit allows only five attempts in ten minutes, and the feedback you've already sent counts towards it. Rate counters live in Craft's data cache, so clear it before you start, which gives these tests a fresh allowance:

```shell
ddev craft clear-caches/data
```

Clearing the data cache also forgets which forms have been used, so only do this on a development site. A live site shouldn't clear that cache as routine maintenance.

### A Normal Submission

Open an article in a private window, write some feedback, add an email address such as `sam@example.com`, wait a few seconds and choose **Send Feedback**. The page reloads at the form with “Thanks for your feedback.” and an empty form, and the count goes up by one. This is the result every person sending feedback should get.

### Sending Too Quickly

The minimum time stops scripts that post a form the instant it loads. With the Turnstile stage in place, a person can't trip it, because the script holds a quick submission until three seconds have passed. Reload the article, type a word and choose **Send Feedback** straight away. The form waits a moment longer, then it's sent normally and the count goes up by one.

To see the server refuse a quick submission, do what a script would do and post the form without the script. Turn off JavaScript in your browser's developer tools, reload the article, type a word and choose **Send Feedback** immediately. The page comes back with “We couldn’t accept that. Please check your feedback and send it again.” and your text still in the box, and the Network panel shows a status of 400. The log shows `minimum-time`, and the count hasn't changed.

Try it once more without JavaScript, but wait five seconds before sending. This time the log shows `challenge-token-missing`, because the minimum time passed and the challenge was the next check to fail. Protect checks the form's timing before it asks for a challenge, so the quick attempt never got that far. Without the Turnstile stage, this slower attempt would succeed. Turn JavaScript back on before you continue.

### A Filled Honeypot

The honeypot is hidden from people, so to test it you have to fill it the way a bot would. Reload the article, open the developer tools console and run:

```js
document.querySelector('.protect-honeypot input').value = 'https://example.com/offer';
```

Then type some feedback and send it. The page comes back with “We couldn’t accept that. Please check your feedback and send it again.” and your text in the box, the log shows `honeypot-populated`, and the count hasn't changed. Because the honeypot is checked first, this attempt didn't use one of your rate-limit attempts, and Cloudflare was never asked to verify the token Turnstile issued in the browser.

The form that came back is newly prepared, with a different, empty honeypot field. Choose **Send Feedback** again and it goes through normally, which is what would happen for a person who had somehow ended up with text in the field.

### Sending the Same Form Twice

Replay protection refuses a form that has already been sent, which is how a bot replaying a captured request is caught. To act like one, you need to send an exact copy of a request that has already succeeded.

The earlier tests have used most of the rate limit's five attempts, so run `ddev craft clear-caches/data` again first. Then open the developer tools' Network panel, turn on its option to preserve the log across page loads, and send a piece of feedback normally. Find the `POST` request to the article's URL, right-click it and copy it as a cURL command. Paste the command into your terminal, add the following to the end so curl prints only the status code, and run it. If curl reports a problem with your local site's certificate, add `--insecure` as well.

```shell
 -s -o /dev/null -w '%{http_code}\n'
```

curl prints `400`. The request carried the same CSRF token, the same message and the same prepared form as the one that succeeded, and the log shows `replay-detected`. The count only went up by one, for the original submission. With the Turnstile stage in place, the copied request also carries the token from the first submission, but replay protection refuses it before Protect would ask Cloudflare about it.

A visitor who's sent feedback never meets this refusal in normal use, because every page they're shown has a freshly prepared form. Using the browser's back button to an old copy of the page is the most likely way a person would see it, and sending again from the page that comes back works.

### Too Much Feedback in a Short Time

The rate limit allows five attempts from one address in each ten-minute window. Every attempt that gets as far as the rate limit counts, including those refused later by Turnstile, while attempts refused by the honeypot, the minimum time or replay protection don't. The original submission in the last test counted, and the replayed copy didn't. Send feedback normally, waiting a few seconds each time, until a submission comes back with this message instead of the thank-you:

“You’ve sent a lot of feedback in a short time. Please wait 7 minutes and send it again.”

Your text is still in the box. The log shows `rate-limit-exceeded`, and the count stopped going up at the last accepted submission. If you open that last request in the Network panel, its status is 429, and its `Retry-After` response header holds the number of seconds until the window ends, which the message rounds up to minutes.

Windows are fixed to the clock, so if a ten-minute boundary passes while you're testing, the count starts again and you'll need a few more submissions to reach the limit. Once the window ends, sending the same text again from the page works, and on your development site `ddev craft clear-caches/data` resets the counts straight away.

A different visitor isn't affected by your limit. If you have another device on a different network, such as a phone using mobile data, the form still accepts feedback from it while you're limited, which confirms that Protect is seeing each visitor's own address. On a site behind a proxy, check this once `trustedHosts` is set, because a wrong setting shows up here as everyone sharing one limit.

## What Your Plugin Owns

Protect checks each attempt and reports what it found, and the rest is your plugin's responsibility. Most of it is already in the code above, but a few parts are worth knowing about as Feedback grows.

Feedback has no screen for reading what visitors send, so you'll want to add one, such as a control panel section listing rows from `feedback_submissions` with the article each one is about, or an email notification sent from the controller after `save()`. Both are ordinary Craft plugin work and don't involve Protect.

The checks in this guide stop automated junk, but they can't judge what a message says, so a person can still send spam by hand. Protect can also send the message text to Akismet for classification and report whether it looks like spam, leaving your plugin to decide whether to refuse it or hold it for review. [Spam Classification](../feature-tour/spam-classification.md) adds the `Classification` check with an `Akismet` provider, including how to hold a flagged message for review alongside the outcomes you've already handled.

If your production site runs on more than one web server, every server has to see the same cache and mutex, such as Redis, or each server keeps its own rate counters and record of used forms. [Shared Cache and Mutex](../get-started/installation-setup.md#shared-cache-and-mutex) explains what to configure.
