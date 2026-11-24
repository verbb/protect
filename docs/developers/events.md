# Events

Protect provides events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to change Protect’s behaviour.

Protect's events register your own classes. Each type service fires its event once per request, the first time it needs its list of types, and checks every registered class as it collects them. A class that doesn't extend the right base class or has an invalid handle throws an `InvalidComponentException`, and a different class reusing a taken handle throws a `DuplicateRegistrationException`.

## Registration Events

### The `registerCheckTypes` Event

The event that is triggered when Protect collects the check types it can run. Add your class to `$event->types` to make it available to plans. See [Custom Checks](custom-checks.md).

```php
use example\protection\BlockedEmailDomains;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Checks;
use yii\base\Event;

Event::on(Checks::class, Checks::EVENT_REGISTER_CHECK_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = BlockedEmailDomains::class;
});
```

### The `registerChallengeTypes` Event

The event that is triggered when Protect collects the challenge providers it can use. Add your class to `$event->types` to make it available to `Challenge` checks. See [Custom Challenge Providers](custom-challenge-providers.md).

```php
use example\protection\ExampleWidget;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Challenges;
use yii\base\Event;

Event::on(Challenges::class, Challenges::EVENT_REGISTER_CHALLENGE_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = ExampleWidget::class;
});
```

### The `registerClassifierTypes` Event

The event that is triggered when Protect collects the classifiers it can use. Add your class to `$event->types` to make it available to `Classification` checks. See [Custom Classifiers](custom-classifiers.md).

```php
use example\protection\PhraseClassifier;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Classifiers;
use yii\base\Event;

Event::on(Classifiers::class, Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = PhraseClassifier::class;
});
```
