<?php
$loader = require $argv[1];
require dirname($argv[1]) . '/yiisoft/yii2/Yii.php';
$loader->addPsr4('verbb\\protect\\', dirname(__DIR__, 2) . '/src', true);
$cache = new \yii\caching\FileCache(['cachePath' => $argv[2]]);
$mutex = new \yii\mutex\FileMutex(['mutexPath' => $argv[2]]);
$state = new \verbb\protect\services\State(['cache' => $cache, 'mutex' => $mutex]);
$claimed = $state->claim($argv[3] . '-replay', 60) === \verbb\protect\services\State::CLAIMED;
$counts = [];
for ($i = 0; $i < 8; $i++) {
    $counts[] = $state->increment($argv[3] . '-count', 60);
}
echo json_encode(['claimed' => $claimed, 'counts' => $counts]);
