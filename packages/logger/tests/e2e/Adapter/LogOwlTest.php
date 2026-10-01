<?php

namespace Utopia\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\LogOwl;
use Utopia\Tests\E2E\AdapterBase;

class LogOwlTest extends AdapterBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $logOwlKey = \getenv('TEST_LOGOWL_KEY');
        $this->adapter = new LogOwl($logOwlKey ? $logOwlKey : '');
        $this->invalidAdapter = new LogOwl('abc', 'https://api.invalid.io/logging/');
    }
}
