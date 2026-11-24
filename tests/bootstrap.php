<?php

// Boot a temporary Craft application with no database. Protect has no tables, so nothing here
// needs one. Set PROTECT_TEST_VENDOR_PATH to run against another installed Craft dependency tree.
$vendor = getenv('PROTECT_TEST_VENDOR_PATH') ?: dirname(__DIR__) . '/vendor';

if (!is_file($vendor . '/autoload.php')) {
    throw new RuntimeException('Install Composer dependencies or set PROTECT_TEST_VENDOR_PATH to an installed Craft vendor directory.');
}

$root = sys_get_temp_dir() . '/protect-tests-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0775, true);
// A real site has its web root, where asset bundles are published, and older Craft won't create it.
mkdir($root . '/web/cpresources', 0775, true);
file_put_contents($root . '/config/general.php', '<?php return ["securityKey" => "protect-tests-only", "allowAdminChanges" => false];');
file_put_contents($root . '/config/db.php', '<?php return ["dsn" => "sqlite::memory:"];');

foreach (getenv() as $key => $value) {
    if (str_starts_with($key, 'CRAFT_') || in_array($key, ['ENVIRONMENT', 'DOTENV_FILE'], true)) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

define('CRAFT_BASE_PATH', $root);
define('CRAFT_VENDOR_PATH', realpath($vendor));
define('CRAFT_CONFIG_PATH', $root . '/config');
define('CRAFT_STORAGE_PATH', $root . '/storage');
define('CRAFT_RUNTIME_PATH', $root . '/storage/runtime');
define('CRAFT_WEB_ROOT', $root . '/web');
define('CRAFT_ENVIRONMENT', 'testing');

$loader = require CRAFT_VENDOR_PATH . '/autoload.php';
$loader->addPsr4('verbb\\protect\\', dirname(__DIR__) . '/src', true);
$loader->addPsr4('Tests\\', __DIR__, true);

if (realpath((new ReflectionClass(\verbb\protect\Protect::class))->getFileName()) !== realpath(dirname(__DIR__) . '/src/Protect.php')) {
    throw new RuntimeException('The suite loaded a different Protect source tree.');
}

register_shutdown_function(static function() use ($root): void {
    if (class_exists(\craft\helpers\FileHelper::class)) {
        \craft\helpers\FileHelper::removeDirectory($root);
    }
});

require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

fwrite(STDOUT, 'Protect tests: PHP ' . PHP_VERSION . ', Craft ' . Craft::$app->getVersion() . PHP_EOL);
