<?php

use verbb\protect\Protect;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\SubjectReference;

uses(PHPUnit\Framework\TestCase::class)->afterEach(function(): void {
    Craft::$app->setModule(Protect::ID, null);
})->in('Unit');

function protectEvidence(ProtectionPlan $plan, string $action = 'test', ?SubjectReference $subject = null): ProtectionEvidence
{
    $manifest = Protect::getInstance()->prepare(new ProtectionContext($action, subject: $subject), $plan)->browserManifest();

    return new ProtectionEvidence($manifest->preparation);
}

function protectClient(array $responses, ?array &$history = null): Client
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}
