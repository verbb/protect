# Spam Classification

Every other check in Protect judges how a submission arrived. Spam classification judges what it says. It catches the spam that gets past everything else, including links pasted in by people who waited, solved the challenge and posted at a normal pace. Protect sends the content and the request's signals to a spam service and reports its verdict as a finding.

A spam verdict is a judgement about content, and judgements can be wrong. That's why most plugins hold a flagged comment for a moderator rather than rejecting it, and why Protect leaves that decision to your plugin.

Protect includes Akismet, CleanTalk and OOPSpam. Akismet and OOPSpam both accept corrections from your moderators. CleanTalk checks the sender against its blacklists as well as the message. OOPSpam scores each message from 0 to 6 so you can choose the threshold, and keeps no log of your requests unless you ask it to. To use another service, you can add it as your own classifier, and [Write a Remote Classifier](../developers/custom-classifiers.md#write-a-remote-classifier) walks through a complete one.

Each service receives the visitor's message and whatever your plugin passes about them, which can include their name, email address, IP address, user agent and the page they came from. Mention the service you use in your site's privacy policy.

## Adding Akismet

You'll need an Akismet API key. Keep it in an environment variable, then add a `Classification` check with an `Akismet` provider to the plan in your plugin's protection service. This example classifies guest comments on articles:

```php
use verbb\protect\checks\Classification;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\models\ProtectionPlan;

$plan = (new ProtectionPlan(handle: 'example-comments'))
    ->add(new Honeypot())
    ->add(new MinimumTime(['seconds' => 3]))
    ->add(new ReplayProtection())
    ->add(new Classification(['provider' => new Akismet([
        'apiKey' => '$AKISMET_API_KEY',
        'blogUrl' => '$PRIMARY_SITE_URL',
        'contentType' => 'comment',
    ])]));
```

`apiKey` and `blogUrl` are required and accept environment expressions. `blogUrl` is the front page of the site making the request. The API key never appears in the plan's fingerprint, a browser manifest or a result.

`contentType` tells Akismet what kind of content it's looking at, and defaults to `comment`. Akismet documents `comment`, `forum-post`, `reply`, `blog-post`, `contact-form`, `signup` and `message`, so a feedback form would use `contact-form` and a forum would use `forum-post` or `reply`. `blogLanguage` defaults to the first two letters of Craft's application language, and you can set it to a code such as `en` when your content is in a different language. `testMode` defaults to `false`, and setting it to `true` marks every request as a test, which is useful on a development site.

Classification runs last, after the passive, stateful and challenge checks. Under the default continuation strategy, an attempt that fails any earlier check never reaches Akismet, so bots caught by a honeypot don't use your Akismet quota.

## Adding CleanTalk

CleanTalk needs an access key from your CleanTalk account. Keep it in an environment variable and use the `CleanTalk` provider:

```php
use verbb\protect\checks\Classification;
use verbb\protect\integrations\classifiers\CleanTalk;

$classification = new Classification(['provider' => new CleanTalk([
    'apiKey' => '$CLEANTALK_ACCESS_KEY',
])]);
```

`apiKey` is the only setting. It accepts an environment expression and never appears in the plan's fingerprint, a browser manifest or a result.

Protect sends CleanTalk the message, the author's name, their email address, IP address, user agent and referrer, whichever the context carries. CleanTalk works without the IP address, but its blacklists can't help without it, so pass it. It also sends two signals from the form itself. When the plan has a check that uses the preparation, such as a honeypot or replay protection, Protect knows from the signed preparation when the form was rendered, and tells CleanTalk how many seconds the visitor took. When the browser package reports that JavaScript ran, which needs the [JavaScript presence check](passive-checks.md#javascript-presence) in the plan, Protect tells CleanTalk that too. Without either, Protect leaves the field out rather than guessing, because CleanTalk counts a very fast or JavaScript-free submission against the sender.

CleanTalk's verdict becomes a `Passed` result, or a `Failed` result with a `SpamDetected` finding and the code `spam-detected`. An unknown or disabled access key, or an expired trial, is reported as a `ConfigurationError` with the code `provider-configuration`, whatever verdict comes with it, and Protect logs CleanTalk's explanation. Server errors, throttling and dropped connections are `provider-unavailable`.

CleanTalk takes corrections only by the ID of the original request, which a passing result doesn't keep, so the `CleanTalk` provider doesn't send feedback.

## Adding OOPSpam

OOPSpam needs an API key from your OOPSpam dashboard. Keep it in an environment variable and use the `OopSpam` provider:

```php
use verbb\protect\checks\Classification;
use verbb\protect\integrations\classifiers\OopSpam;

$classification = new Classification(['provider' => new OopSpam([
    'apiKey' => '$OOPSPAM_API_KEY',
    'spamThreshold' => 3,
    'allowedLanguages' => ['en'],
])]);
```

OOPSpam scores each message from 0 to 6, and `spamThreshold` sets the score that counts as spam. It defaults to `3`, which is OOPSpam's own recommendation. A message at or above it fails with the code `spam-detected`, and the finding's metadata records the `score` and the `threshold`, which helps you tune the value from real traffic.

Protect sends OOPSpam the message, the visitor's IP address and their email address, whichever the context carries. OOPSpam can score a message without either, but checks both against its denylists when they're present. OOPSpam treats localhost and private IP addresses as blocked, so a message sent from your own development machine scores higher than it would on a live site.

The other settings switch on OOPSpam's optional rules:

| Setting | Default | What It Does |
| --- | --- | --- |
| `checkForLength` | `false` | Scores any message of 20 characters or fewer as spam. Protect turns this off, unlike OOPSpam's API, because it would hold every short “Thanks!” comment. Turn it on for forms where a short message is suspicious, such as a contact form. |
| `allowedLanguages` | `[]` | Two-letter language codes such as `['en']`. A message in any other language scores higher. |
| `allowedCountries` | `[]` | Two-letter country codes. A message from any other country gets the highest score. |
| `blockedCountries` | `[]` | Two-letter country codes. A message from any of them gets the highest score. |
| `blockTempEmail` | `false` | Treats a disposable email address as spam. |
| `blockVPN` | `false` | Treats VPN, proxy and Tor addresses as spam. |
| `blockDC` | `false` | Treats addresses from data centres, such as cloud servers, as spam. |
| `urlFriendly` | `false` | Reduces how much links in a message raise its score. |
| `logIt` | `false` | Keeps a log of each request in your OOPSpam dashboard. Requires `source`. |
| `source` | None | A name for your site, such as its domain, which OOPSpam shows beside the logged requests. |

A country rule needs the visitor's IP address, so when `allowedCountries` or `blockedCountries` is set, a context without one returns an `Error` result with the code `missing-required-signal`, as Akismet does.

A wrong API key, or a setting OOPSpam rejects, is reported as a `ConfigurationError` with the code `provider-configuration`. Server errors and throttling are `provider-unavailable`. OOPSpam's quota is monthly, so an exhausted quota also arrives as throttling and looks like an outage until it resets. Protect logs each error, so watch the log if you're close to your limit.

## Describing the Content

Every classifier needs to know what was submitted and by whom. Pass it on the context as a `ProtectionContent`, with the visitor's email as an identity signal and the article as the subject. In this partial controller code, `$article` is the entry your plugin has already resolved, `$evidence` is built from the submitted `__protect` values, and `$plan` comes from your protection service:

```php
use Craft;
use verbb\protect\Protect;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

$request = Craft::$app->getRequest();

$result = Protect::getInstance()->check(new ProtectionContext(
    action: 'example.comments.create',
    identity: new ProtectionIdentity(['email' => $request->getBodyParam('email')]),
    subject: SubjectReference::fromElement($article),
    content: new ProtectionContent(
        text: $request->getBodyParam('text'),
        authorName: $request->getBodyParam('name'),
        authorUrl: $request->getBodyParam('website'),
    ),
    request: RequestSignals::fromRequest($request, userAgent: true, referrer: true),
    evidence: $evidence,
), $plan);
```

Validate these values as your plugin normally would before checking. The email here is what the visitor typed, which is fine for describing content to a classifier, but it isn't trustworthy enough to use as an identity for [rate limits](rate-limits.md).

`ProtectionContent` takes the comment's `text`, `authorName` and `authorUrl`. For anything other than a brand new submission, also pass `dateCreated` with the original date and set `edited` to `true` when someone has changed existing content, which tells Akismet it's rechecking an edit. A `fields` array can carry extra scalar values for a [custom classifier](../developers/custom-classifiers.md), but none of the built-in classifiers send them. CleanTalk also receives `authorName`, and OOPSpam receives only the `text`. `SubjectReference::fromElement()` includes the article's URL, which Akismet receives as the page the comment was posted on.

When the context has no content at all, classification is skipped and its result is `Skipped` with the reason `not-applicable`. Actions without text, such as votes, can share a plan with classification in it, or leave it out.

## The Signals Akismet Uses

Akismet's accuracy depends on knowing about the request as well as the content, and its own documentation warns that results get worse when you leave data out. `RequestSignals::fromRequest()` collects the visitor's IP address by default, and the user agent and referrer only when you ask for them, as the example above does. Pass both when classifying.

The IP address is required. When the context has no address, such as a check made from a console command, the classification check returns an `Error` result with a `MissingRequiredSignal` finding and the code `missing-required-signal`, instead of asking Akismet with half the picture. Akismet receives the same address as your rate limits, so [configure your proxy](../get-started/installation-setup.md#configure-your-proxy-first) before relying on either.

When the plan also has a honeypot, Protect sends Akismet the honeypot field's name and value. Under the default continuation strategy a filled honeypot stops the run before classification, so Akismet usually sees it empty.

## Akismet's Verdicts

Akismet answers each check with a verdict, and Protect turns it into a result:

| Akismet's Answer | Result | Finding | Code |
| --- | --- | --- | --- |
| Spam | Failed | `SpamDetected` | `spam-detected` |
| Not spam | Passed | None | None |
| An error message instead of a verdict | Error | `ConfigurationError` | `provider-configuration` |
| A server error, throttling or no connection | Error | `ProviderUnavailable` | `provider-unavailable` |
| Another unexpected HTTP status | Error | `ProviderUnavailable` | `provider-http-error` |

A revoked or mistyped API key is the usual reason for an error message instead of a verdict. Protect logs the explanation Akismet sends with it, so you can see why without capturing the request. Akismet can also send an account alert, such as a warning that you're over your plan's limit, alongside a valid verdict. Protect keeps the verdict and logs the alert at most once a day.

## Holding Spam for Moderation

A `SpamDetected` finding makes the result's `hasAdverseFindings()` return `true`, like any other failed check. If your plugin has a moderation queue, look for the spam finding before your general rejection, and save the comment with your plugin's pending status instead of turning the visitor away:

```php
use verbb\protect\enums\FindingType;
use yii\web\HttpException;

// Partial controller code. $result is the ProtectionResult returned by check().
$holdForModeration = $result->hasFinding(FindingType::SpamDetected);

if ($result->hasAdverseFindings() && !$holdForModeration) {
    throw new HttpException(400, 'Refresh the page and try again.');
}

// Save the comment, using your plugin's pending status when $holdForModeration is true.
```

Because classification runs last and the default strategy stops at the first failure, a spam finding means every earlier check passed. If your plan uses `ContinuationStrategy::RunAll`, check for other adverse findings as well. An Akismet outage is a good case for holding comments too, so a temporary problem doesn't publish spam or turn away genuine visitors. [Results and Findings](results-and-findings.md) puts this together with rate limits and configuration errors.

Protect never changes anything's moderation status itself. What a held comment looks like, who can see it, and what the visitor is told are all up to your plugin.

## Reporting Mistakes Back to the Service

When a moderator marks a held comment as genuine, or a published comment as spam, telling the service helps it judge similar content on your site. The `Akismet` and `OopSpam` providers implement `ClassifierFeedbackInterface`, whose `feedback()` method sends that correction. OOPSpam's reports appear in its dashboard. Pass `true` to report spam that was missed, or `false` to report a genuine comment that was flagged.

Feedback is never sent automatically. Your plugin calls it when a moderator acts, which usually happens in a later control panel request, long after the visitor has gone. Both services ask for the same information as the original check, so your plugin needs to keep the request signals with the comment when it's submitted if it wants to report on it later. Whether to store a visitor's IP address, user agent and referrer, and for how long, is a privacy decision for your plugin and its users.

This example sends a correction from your plugin's moderation service. `$comment` is your own comment element, and the properties read from it are whatever your plugin stored at submission:

```php
use verbb\protect\enums\CheckStatus;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\models\ProtectionContent;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionIdentity;
use verbb\protect\models\RequestSignals;
use verbb\protect\models\SubjectReference;

$akismet = new Akismet([
    'apiKey' => '$AKISMET_API_KEY',
    'blogUrl' => '$PRIMARY_SITE_URL',
    'contentType' => 'comment',
]);

$feedback = $akismet->feedback(new ProtectionContext(
    action: 'example.comments.create',
    identity: new ProtectionIdentity(['email' => $comment->email]),
    subject: SubjectReference::fromElement($comment->getArticle()),
    content: new ProtectionContent(
        text: $comment->text,
        authorName: $comment->authorName,
        dateCreated: $comment->dateCreated,
    ),
    request: new RequestSignals($comment->ipAddress, $comment->userAgent, $comment->referrer),
), spam: true);

if ($feedback->status !== CheckStatus::Passed) {
    // Akismet didn't accept the report. Protect has already logged why.
}
```

Use the same provider settings as your plan, so the report goes to the same Akismet site or OOPSpam account. A successful report returns a `Passed` result with the code `feedback-received`. Without an IP address, Akismet's `feedback()` returns an `Error` result with the code `missing-required-signal`, and other failures use the same codes as a check. Protect logs a failed report, because feedback runs outside `check()` and would otherwise go unrecorded.

## Testing Classification

For Akismet, turn on `testMode` on a development site, so every request Akismet receives from it is marked as a test. To see a spam verdict, submit a comment with the name `akismet-guaranteed-spam`, which Akismet documents as always returning spam. Check that the comment is held for moderation, or whatever your plugin does with a `SpamDetected` finding, and that an ordinary comment is published.

CleanTalk and OOPSpam have no test mode. Check a spam verdict with an obvious spam message, such as one full of links to unrelated products, and keep in mind that OOPSpam scores development machines' private IP addresses as spam.
