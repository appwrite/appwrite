<?php

require_once __DIR__.'/init.php';
require_once __DIR__.'/controllers/general.php';

use Utopia\App;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;

Authorization::disable();

$cli = new CLI();

include 'tasks/doctor.php';
include 'tasks/maintenance.php';
include 'tasks/install.php';
include 'tasks/migrate.php';
include 'tasks/sdks.php';
include 'tasks/specs.php';
include 'tasks/ssl.php';
include 'tasks/vars.php';
include 'tasks/usage.php';

$cli
    ->task('version')
    ->desc('Get the server version')
    ->action(function () {
        Console::log(App::getEnv('_APP_VERSION', 'UNKNOWN'));
    });

$cli->run();
