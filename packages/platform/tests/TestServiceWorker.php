<?php

declare(strict_types=1);

namespace Utopia\Platform\Tests;

use Utopia\Platform\Service;

class TestServiceWorker extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction('workerStartHook', TestActionWorkerStart::class);
        $this->addAction('workerStopHook', TestActionWorkerStop::class);
    }
}
