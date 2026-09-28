<?php

namespace Utopia\Tests;

use Utopia\Platform\Platform;

class TestPlatform extends Platform
{
    public function __construct()
    {
        $module = new TestModule();
        parent::__construct($module);

        $this->addService('testService', new TestService());
        $this->addService('testCli', new TestServiceCLI());
        $this->addService('testWorker', new TestServiceWorker());
    }
}
