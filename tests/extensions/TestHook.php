<?php

namespace Appwrite\Tests;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class TestHook implements Extension
{
    protected const MAX_SECONDS_ALLOWED = 15;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new TestFinishedSubscriber(self::MAX_SECONDS_ALLOWED));
        $facade->registerSubscriber(new RetrySubscriber());
    }
}
