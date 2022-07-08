<?php
namespace Appwrite\Task;

use Utopia\App;
use Appwrite\Task\Task;
use Utopia\CLI\Task as CLITask;
use Utopia\CLI\Console;

class Version implements Task {
    protected static CLITask $task;

    public static function getTask(): CLITask {
        $version = new CLITask('version');
        $version
            ->desc('Get the server version')
            ->action(function () {
                Console::log(App::getEnv('_APP_VERSION', 'UNKNOWN'));
            });
        self::$task = $version;
        return self::$task;
    }
}
