<?php

namespace Tests\Benchmarks\Functions;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Tests\E2E\Scopes\SideServer;

class FunctionsCustomServerBench extends Base
{
    use SideServer;

    #[Revs(1)]
    #[Iterations(1)]
    #[BeforeMethods(['createFunction', 'prepareDeployment'])]
    public function benchDeploymentCreate()
    {
        $this->createDeployment();
    }
}
