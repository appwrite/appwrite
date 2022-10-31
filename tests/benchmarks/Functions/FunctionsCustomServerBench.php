<?php

namespace Tests\Benchmarks\Functions;

use PhpBench\Attributes\BeforeMethods;
use Tests\E2E\Scopes\SideServer;

class FunctionsCustomServerBench extends Base
{
    use SideServer;

    #[BeforeMethods(['createFunction', 'prepareDeployment'])]
    public function benchDeploymentCreate()
    {
        $this->createDeployment();
    }
}
