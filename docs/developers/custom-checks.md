# Custom Checks

A check is one step in a protection plan, such as the honeypot, the minimum time or a rate limit. A custom check is for anything that isn't a challenge or a content classifier, such as a rule about the visitor's email address or an account's history.

## Create the Check Class

Extend `verbb\protect\base\Check`. Suppose your feedback form attracts messages from a handful of throwaway email domains. This check refuses any attempt whose email address uses one of them:

```php
namespace example\protection;

use verbb\protect\base\Check;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\enums\IdentitySignalType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;

class BlockedEmailDomains extends Check
{
    public array $domains = [];

    public static function handle(): string
    {
        return 'example-blocked-email-domains';
    }

    public static function displayName(): string
    {
        return 'Blocked Email Domains';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Passive;
    }

    public function isApplicable(ProtectionContext $context): bool
    {
        return is_string($context->identity?->get(IdentitySignalType::Email));
    }

    public function check(ProtectionContext $context): CheckResult
    {
        $email = (string)$context->identity->get(IdentitySignalType::Email);
        $domain = strtolower(substr(strrchr($email, '@') ?: '@', 1));

        if (in_array($domain, array_map('strtolower', $this->domains), true)) {
            return $this->failed(FindingType::PassiveCheckFailed, 'email-domain-blocked');
        }

        return $this->passed();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['domains'], 'each', 'rule' => ['string', 'min' => 1]];

        return $rules;
    }
}
```

`stage()` decides when the check runs. This one only looks at the context, so it runs with the passive checks, before anything is counted or sent to a provider. A check that records something belongs in `Stateful`, and one that calls an outside service belongs in `Challenge` or `Classification`. `isApplicable()` returns `false` when there's nothing to examine, here an attempt without an email address, which records the check as `Skipped`. `check()` returns a result through the base class's `passed()` and `failed()` helpers, or a `CheckResult` you build yourself, such as an `Error` result when something the check depends on is unavailable. Your check also gets the settings every check shares, such as `enabled`, `priority` and `condition`, and its public settings become part of the plan's fingerprint.

## Register the Check

Protect finds checks through the `registerCheckTypes` event. Register your class from your module or plugin’s `init()` method.

```php
use example\protection\BlockedEmailDomains;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Checks;
use yii\base\Event;

Event::on(
    Checks::class,
    Checks::EVENT_REGISTER_CHECK_TYPES,
    static function(RegisterComponentTypesEvent $event): void {
        $event->types[] = BlockedEmailDomains::class;
    },
);
```

## Add the Check to a Plan

Add the check to a plan with its settings, and pass the email in the context's identity. In this partial example, `$plan` is the plan from your protection service:

```php
use example\protection\BlockedEmailDomains;

$plan->add(new BlockedEmailDomains(['domains' => ['mailinator.com', 'guerrillamail.com']]));
```

The email here is whatever the visitor typed. That's fine for a check that judges the address itself, but don't use a typed value as a rate-limit identity, because a visitor can change it on every attempt.

## Read the Signed Preparation

Built-in checks that read the signed preparation implement `PreparableCheckInterface`. Implementing it in your own check makes Protect verify the preparation before your check runs, and gives your `check()` access to its values through `$context->prepared()`, such as `nonce` and `issuedAt`. Its `prepare()` method can't add values of your own to the preparation, because Protect only keeps the built-in ones. Pass anything else your check needs through the context's `metadata`.

## Methods

::: reference
### `handle()`

**Returns:** `string`

Returns the check's handle, which identifies it in results and logs. It must be lowercase, start with a letter and use only letters, digits and hyphens, and it should start with your plugin's handle so it can't clash with another plugin's. This method is static.
:::

::: reference
### `displayName()`

**Returns:** `string`

Returns the name of the check as people should see it, such as in a settings screen. This method is static.
:::

::: reference
### `stage()`

**Returns:** `ExecutionStage`

Returns the stage the check runs in, which is `Passive`, `Stateful`, `Challenge` or `Classification`. Checks run in that order, then by `priority` within a stage. This method is static.
:::

::: reference
### `isApplicable()`

**Arguments:** `ProtectionContext $context`, the attempt being checked.

**Returns:** `bool`

Returns whether the check has anything to examine for this attempt. When it returns `false`, the check is recorded as `Skipped`. The default is `true`.
:::

::: reference
### `check()`

**Arguments:** `ProtectionContext $context`, the attempt being checked.

**Returns:** `CheckResult`

Returns the check's result for the attempt. Use the `passed()` and `failed()` helpers, or build a `CheckResult` yourself for an `Error`.
:::

::: reference
### `secretAttributes()`

**Returns:** `string[]`

Returns the names of the settings that hold credentials. These are left out of the plan's fingerprint. The default is an empty array.
:::

::: reference
### `defineRules()`

**Returns:** `array`

Returns the validation rules for the check's settings. Merge your rules with the parent's, and a plan with invalid settings throws an `InvalidPlanException` when it's prepared or checked.
:::
