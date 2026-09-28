<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Platform\Service;

class TestServiceCLI extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;
        $this->addAction('build', new TestActionCLI());
        $this->addAction('build2', new TestActionCLI());
    }
}
