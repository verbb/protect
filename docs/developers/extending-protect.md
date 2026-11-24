# Extending Protect

Protect's built-in checks and providers cover the common defences, and you can add your own alongside them. You can write a [classifier](custom-classifiers.md) for content, a [challenge provider](custom-challenge-providers.md) for a verification service Protect doesn't include, and a [check](custom-checks.md) for anything else, and you can keep Protect's records somewhere other than Craft's cache. Each kind of class extends a base class from `verbb\protect\base`, is registered with Protect, and is added to a plan like the built-in ones.

Your classes report what they find, the same way the built-in ones do. Deciding whether to refuse, slow down or hold an attempt stays with the plugin that uses them.

## Register Your Classes

Protect only runs classes that have been registered, so a plan containing an unregistered class fails validation with an `InvalidComponentException`. Register each class once, in your plugin's or module's `init()`, through the event on the matching service:

| Kind | Base Class | Event |
| --- | --- | --- |
| [Check](custom-checks.md) | `base\Check` | `services\Checks::EVENT_REGISTER_CHECK_TYPES` |
| [Challenge provider](custom-challenge-providers.md) | `base\ChallengeProvider` | `services\Challenges::EVENT_REGISTER_CHALLENGE_TYPES` |
| [Classifier](custom-classifiers.md) | `base\ClassifierProvider` | `services\Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES` |

Every class needs a static `handle()` that returns a lowercase handle starting with a letter, using only letters, digits and hyphens, such as `example-phrases`. Prefix it with your plugin's handle so it can't clash with another plugin's. Registering the same class twice is harmless, but registering a different class with a handle that's already taken throws a `DuplicateRegistrationException`, and an invalid handle throws an `InvalidComponentException`.

## Replace the State Backend

Replay protection and rate limits keep their records in Protect's `State` component, which uses Craft's cache and mutex. To use another backend, subclass `verbb\protect\services\State` and replace the component through the module's config in `config/app.php`:

```php
// config/app.php
return [
    'modules' => [
        'verbb-protect' => [
            'class' => verbb\protect\Protect::class,
            'components' => ['state' => modules\MyState::class],
        ],
    ],
];
```

`State` has three methods. `claim(key, ttl, value)` records a key once and returns `State::CLAIMED`, or `State::REPEATED` when the same key is claimed again with the same non-null value, or `State::DENIED` otherwise. `increment(key, ttl)` adds one to a counter and returns the new count. `peek(key)` reads a counter without changing it. A replacement must make claims and increments atomic across every server in your deployment, and throw a `StateException` when its backend fails, so the built-in checks can report `state-unavailable` and your plugin's outage policy applies.

Your own plugin can use the same component for a budget that checks a limit first and counts an attempt only once it knows the outcome. Build the key with `Protect::getInstance()->stateKey()`, read it with `peek()`, and count with `increment()`. In this partial example, `$userId` is the signed-in user's ID:

```php
use verbb\protect\Protect;

$protect = Protect::getInstance();
$key = $protect->stateKey(['example-feedback-budget', $userId]);

if ($protect->getState()->peek($key) < 20) {
    // Do the work, then count it.
    $protect->getState()->increment($key, 86400);
}
```

`peek()` and `increment()` aren't atomic together, so two parallel requests can both see a count under the limit. Use this for soft budgets, and a rate limit for anything that must hold exactly.
