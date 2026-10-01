<?php

namespace Utopia\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\Raygun;
use Utopia\Tests\E2E\AdapterBase;

class RaygunTest extends AdapterBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $raygunKey = \getenv('TEST_RAYGUN_KEY');
        $this->adapter = new Raygun($raygunKey ? $raygunKey : '');
        $this->invalidAdapter = new Raygun('');
        $this->expected = 202;
    }
}
