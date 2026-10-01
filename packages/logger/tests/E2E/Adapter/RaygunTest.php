<?php

namespace Utopia\Logger\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Tests\E2E\AdapterBase;

class RaygunTest extends AdapterBase
{
    #[\Override]
    protected string $credential = 'TEST_RAYGUN_KEY';

    protected function setUp(): void
    {
        parent::setUp();
        $raygunKey = \getenv('TEST_RAYGUN_KEY');
        $this->adapter = $raygunKey ? new Raygun($raygunKey) : null;
        $this->invalidAdapter = new Raygun('');
        $this->expected = 202;
    }
}
