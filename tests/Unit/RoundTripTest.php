<?php

use verbb\protect\Protect;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionEvidence;
use verbb\protect\services\State;

use yii\caching\ArrayCache;

use GuzzleHttp\Psr7\Response;

// The other half of the PHP and browser round trip: see packages/protect/tests/round-trip.test.mjs.

function roundTripFixture(string $name): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2) . '/packages/protect/tests/fixtures/' . $name), true, 16, JSON_THROW_ON_ERROR);
}

function roundTripShape(array $data): array
{
    $shape = [];

    foreach ($data as $key => $value) {
        // Honeypot field names are random, so only the namespace identifies them.
        $key = is_string($key) ? preg_replace('/\[honeypot\]\[[a-f0-9]+\]/', '[honeypot][*]', $key) : $key;
        $shape[$key] = is_array($value) ? roundTripShape($value) : get_debug_type($value);
    }

    return $shape;
}

function roundTripPlan(array $responses = [])
{
    $factory = require dirname(__DIR__) . '/fixtures/round-trip-plan.php';

    return $factory(protectClient($responses));
}

it('still prepares the shape the committed fixture records', function() {
    $protect = Protect::getInstance();
    $fixture = roundTripFixture('preparation.json');
    $protect->getPreparations()->clock = fn() => $fixture['manifest']['issuedAt'];
    $preparation = $protect->prepare(new ProtectionContext('round-trip.submit'), roundTripPlan());

    expect(roundTripShape(json_decode(json_encode($preparation), true)))->toBe(roundTripShape($fixture));
});

it('accepts evidence the browser package produced from a real preparation', function(string $source) {
    $protect = Protect::getInstance();
    $fixture = roundTripFixture('preparation.json');
    $submitted = roundTripFixture('evidence.json');

    if ($source === 'fields') {
        parse_str(http_build_query($submitted['fields']), $parsed);
        $data = $parsed['__protect'];
    } else {
        $data = $submitted['evidence'];
    }

    $evidence = ProtectionEvidence::fromArray($data);
    // The fixture's single proof is checked twice, once per source, so each gets clean state.
    $protect->set('state', new State(['cache' => new ArrayCache()]));
    $protect->getPreparations()->clock = fn() => $fixture['manifest']['notBefore'];

    $client = [new Response(200, [], json_encode(['success' => true, 'action' => 'round-trip', 'hostname' => 'example.test', 'challenge_ts' => gmdate('c')]))];
    $result = $protect->check(new ProtectionContext('round-trip.submit', evidence: $evidence), roundTripPlan($client));

    expect($evidence->preparation)->toBe($fixture['manifest']['preparation'])
        ->and($evidence->javascript)->toBeTrue()
        ->and($evidence->challenges)->toBe(['turnstile' => 'fixture-token'])
        ->and($evidence->honeypot)->toBe([$fixture['manifest']['honeypot'] => ''])
        ->and($result->checksPassed())->toBeTrue(json_encode($result));
})->with(['fields', 'evidence']);
