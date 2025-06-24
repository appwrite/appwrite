<?php

namespace Appwrite\Platform\Modules\Functions\Workers\Builds;

use Appwrite\Platform\Modules\Functions\Services\Workers;
use Appwrite\Platform\Modules\Functions\Workers\Builds\Actions\Handler as BuildsHandler;
use Appwrite\Platform\Modules\Functions\Workers\Builds\Actions\Init as BuildsInit;
use Appwrite\Platform\Modules\Functions\Workers\Builds\Actions\Shutdown as BuildsShutdown;
use Utopia\Queue\Server;

class Builds
{
    public function __construct(Workers $worker)
    {
        $worker->addAction(BuildsInit::getName(), new BuildsInit());
        $worker->addAction(BuildsHandler::getName(), new BuildsHandler());
        $worker->addAction(BuildsShutdown::getName(), new BuildsShutdown());

        Server::setResource('buildStartTime', function () {
            return \microtime(true);
        });
    }
}
