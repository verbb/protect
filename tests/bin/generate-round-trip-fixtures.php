<?php

use verbb\protect\Protect;
use verbb\protect\models\ProtectionContext;

// Writes what a real prepare() produces for the browser package's round-trip test. The clock is
// fixed so the proof stays checkable: the PHP round-trip test checks it at this same moment.
const ROUND_TRIP_ISSUED_AT = 1800000000;

require dirname(__DIR__) . '/bootstrap.php';

$protect = Protect::getInstance();
$protect->getPreparations()->clock = static fn() => ROUND_TRIP_ISSUED_AT;
$plan = (require dirname(__DIR__) . '/fixtures/round-trip-plan.php')();
$preparation = $protect->prepare(new ProtectionContext('round-trip.submit'), $plan);

$path = dirname(__DIR__, 2) . '/packages/protect/tests/fixtures/preparation.json';
file_put_contents($path, json_encode($preparation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
fwrite(STDOUT, 'Wrote ' . $path . PHP_EOL);
