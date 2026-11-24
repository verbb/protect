<?php

use verbb\protect\checks\Challenge;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\integrations\challenges\Turnstile;
use verbb\protect\models\ProtectionPlan;

// The plan behind the committed browser round-trip fixtures. Changing it means regenerating them.
return static function(?GuzzleHttp\ClientInterface $client = null): ProtectionPlan {
    return (new ProtectionPlan(handle: 'round-trip-fixture', preparationTtl: 86400))
        ->add(new Honeypot())
        ->add(new MinimumTime(['seconds' => 1]))
        ->add(new JavaScriptPresence())
        ->add(new ReplayProtection())
        ->add(new Challenge(['provider' => new Turnstile([
            'client' => $client,
            'siteKey' => 'fixture-site-key',
            'secretKey' => 'fixture-secret',
            'expectedAction' => 'round-trip',
            'hostnames' => ['example.test'],
        ])]));
};
