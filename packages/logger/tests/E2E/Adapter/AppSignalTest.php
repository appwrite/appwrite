<?php

namespace Utopia\Logger\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Tests\E2E\AdapterBase;

class AppSignalTest extends AdapterBase
{
    #[\Override]
    protected int $expected = 204;

    #[\Override]
    protected string $credential = 'TEST_APPSIGNAL_KEY';

    protected function setUp(): void
    {
        parent::setUp();
        $appSignalKey = \getenv('TEST_APPSIGNAL_KEY');
        $this->adapter = $appSignalKey ? new AppSignal($appSignalKey) : null;
        $this->invalidAdapter = new AppSignal('');
    }
}
