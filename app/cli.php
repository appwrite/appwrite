<?php

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/controllers/general.php';

require_once __DIR__ . 'init/constants.php';
require_once __DIR__ . 'init/config.php';
require_once __DIR__ . 'init/database.php';
require_once __DIR__ . 'init/redis.php';
require_once __DIR__ . 'init/registry.php';
require_once __DIR__ . 'init/locales.php';
require_once __DIR__ . 'init/cli.php';

use Appwrite\Platform\Appwrite;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;
use Utopia\CLI\Console;

Authorization::disable();


$platform = new Appwrite();
$platform->init(Service::TYPE_CLI);

$cli = $platform->getCli();

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli->run();
