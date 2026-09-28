<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Platform\Service;

class TestServiceWorker extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction('workerStartHook', new TestActionWorkerStart());
        $this->addAction('workerStopHook', new TestActionWorkerStop());
    }
}
