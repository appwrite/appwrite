<?php

require_once __DIR__ . '/init/cli.php';

use Appwrite\Platform\Appwrite;
use Utopia\CLI\Console;
use Utopia\Platform\Service;

$platform = new Appwrite();
$platform->init(Service::TYPE_TASK);

$cli = $platform->getCli();

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli->run();
