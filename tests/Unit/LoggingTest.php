<?php

use verbb\protect\Protect;
use verbb\protect\checks\RateLimit;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\RateLimitRule;

it('logs error codes without the posted form', function() {
    $post = $_POST;
    $_POST = [
        'message' => 'posted-message-text',
        'email' => 'posted-visitor@example.com',
        '__protect' => ['preparation' => 'posted-preparation', 'challenges' => ['turnstile' => 'posted-token']],
    ];

    try {
        // A rule keyed on an identity the context doesn't have returns an error, which Protect logs.
        $plan = (new ProtectionPlan(handle: 'logging'))->add(new RateLimit(['rules' => [new RateLimitRule(['dimensions' => ['identity.userId']])]]));
        $result = Protect::getInstance()->check(new ProtectionContext('logging.test'), $plan);

        Craft::getLogger()->flush(true);
    } finally {
        $_POST = $post;
    }

    $log = implode('', array_map('file_get_contents', glob(Craft::getAlias('@storage/logs') . '/protect-*.log')));

    expect($result->hasErrors())->toBeTrue()
        ->and($log)->toContain('returned an error (rate-identity-missing) for logging.test')
        ->and($log)->not->toContain('Request context')
        ->and($log)->not->toContain('posted-message-text')
        ->and($log)->not->toContain('posted-visitor@example.com')
        ->and($log)->not->toContain('posted-preparation')
        ->and($log)->not->toContain('posted-token');
});
