<?php

use verbb\protect\enums\FindingType;
use verbb\protect\services\Classifiers;

use yii\base\Event;

it('runs the documented classifier without hidden application code', function() {
    $page = file_get_contents(dirname(__DIR__, 2) . '/docs/developers/custom-classifiers.md');
    preg_match_all('/```php\n(.*?)\n```/s', $page, $blocks);

    // The local classifier, its registration and the plan are the page's first three PHP examples.
    expect(count($blocks[1]))->toBeGreaterThanOrEqual(3);

    try {
        eval($blocks[1][0]);
        eval($blocks[1][1]);
        eval($blocks[1][2]);

        expect($result->hasFinding(FindingType::SpamDetected))->toBeTrue()
            ->and($result->hasErrors())->toBeFalse();
    } finally {
        Event::off(Classifiers::class, Classifiers::EVENT_REGISTER_CLASSIFIER_TYPES);
    }
});
