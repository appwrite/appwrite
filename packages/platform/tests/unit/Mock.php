<?php

namespace Utopia\Unit;

use Utopia\Platform\Platform;
use Utopia\Tests\TestModule;

class Mock extends Platform
{
    public function __construct()
    {
        $module = new TestModule();
        parent::__construct($module);
    }
}
