<?php

namespace Utopia\Logger\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Tests\E2E\AdapterBase;

class LogOwlTest extends AdapterBase
{
    #[\Override]
    protected string $credential = 'TEST_LOGOWL_KEY';

    protected function setUp(): void
    {
        parent::setUp();
        $logOwlKey = \getenv('TEST_LOGOWL_KEY');
        $this->adapter = $logOwlKey ? new LogOwl($logOwlKey) : null;
        $this->invalidAdapter = new LogOwl('abc', 'https://api.invalid.io/logging/');
    }
}
