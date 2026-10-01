<?php

namespace Utopia\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\AppSignal;
use Utopia\Tests\E2E\AdapterBase;

class AppSignalTest extends AdapterBase
{
    protected int $expected = 204;

    protected function setUp(): void
    {
        parent::setUp();
        $appSignalKey = \getenv('TEST_APPSIGNAL_KEY');
        $this->adapter = new AppSignal($appSignalKey ? $appSignalKey : '');
        $this->invalidAdapter = new AppSignal('');
    }
}
