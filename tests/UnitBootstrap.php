<?php declare(strict_types=1);

$autoload = getenv('LISTRAK_TEST_AUTOLOAD') ?: null;
$directory = dirname(__DIR__);
while ($autoload === null && dirname($directory) !== $directory) {
    if (is_file($directory . '/vendor/autoload.php')) {
        $autoload = $directory . '/vendor/autoload.php';
        break;
    }
    $directory = dirname($directory);
}

if ($autoload === null) {
    throw new RuntimeException('Install Composer dependencies or set LISTRAK_TEST_AUTOLOAD to the Shopware autoloader.');
}

$loader = require $autoload;
$loader->addPsr4('Listrak\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Listrak\\Tests\\', __DIR__ . '/');
