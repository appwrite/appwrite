<?php

namespace Utopia\Platform\Tests;

use Utopia\Platform\Platform;

class Mock extends Platform
{
    public function __construct()
    {
        $module = new TestModule();
        parent::__construct($module);
    }
}
