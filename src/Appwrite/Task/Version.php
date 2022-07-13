<?php
namespace Appwrite\Task;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Platform\Action;

class Version extends Action {
    public function __construct()
    {
        $this
            ->desc('Get the server version')
            ->callback(function () {
                Console::log(App::getEnv('_APP_VERSION', 'UNKNOWN'));
            });
    }
}
