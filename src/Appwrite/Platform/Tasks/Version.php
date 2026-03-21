<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Platform\Action;
use Utopia\System\System;

class Version extends Action
{
    public static function getName(): string
    {
        return 'version';
    }

    public function __construct()
    {
        $this
            ->desc('Get the server version')
            ->callback(function () {
                Console::log(System::getEnv('_APP_VERSION', 'UNKNOWN'));
            });
    }
}
