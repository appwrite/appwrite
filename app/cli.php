<?php

require_once __DIR__.'/workers.php';

use Utopia\App;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;

$cli = new CLI();

include 'tasks/doctor.php';
include 'tasks/maintenance.php';
include 'tasks/install.php';
include 'tasks/migrate.php';
include 'tasks/sdks.php';
include 'tasks/ssl.php';
include 'tasks/vars.php';

$cli
    ->task('version')
    ->desc('Get the server version')
    ->action(function () {
        Console::log(App::getEnv('_APP_VERSION', 'UNKNOWN'));
    });

$cli->run();