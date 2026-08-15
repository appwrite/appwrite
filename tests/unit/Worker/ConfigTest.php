<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use Appwrite\Event\Event;
use Appwrite\Worker\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDatabasesCoroutineCapIsOne(): void
    {
        $this->assertSame(1, Config::maxCoroutines('databases'));
    }

    public function testDatabasesCapIgnoresEnvOverride(): void
    {
        $previous = \getenv('_APP_WORKER_MAX_COROUTINES');
        \putenv('_APP_WORKER_MAX_COROUTINES=8');

        try {
            $this->assertSame(1, Config::maxCoroutines('databases', env: true));
        } finally {
            \putenv($previous === false ? '_APP_WORKER_MAX_COROUTINES' : '_APP_WORKER_MAX_COROUTINES=' . $previous);
        }
    }

    public function testUnknownWorkerDefaultsToOne(): void
    {
        $this->assertSame(1, Config::maxCoroutines('unknown-worker'));
    }

    public function testFunctionsKeepEightCoroutines(): void
    {
        $this->assertSame(8, Config::maxCoroutines('functions'));
    }

    public function testCombinedTotalIsSumOfPerQueueCaps(): void
    {
        $this->assertSame(61, Config::total(Config::NAMES));
    }

    public function testQueueNamesMatchPublishers(): void
    {
        $this->assertSame(Event::FUNCTIONS_QUEUE_NAME, Config::queue('functions'));
        $this->assertSame(Event::MAILS_QUEUE_NAME, Config::queue('mails'));
        $this->assertSame(Event::DELETE_QUEUE_NAME, Config::queue('deletes'));
        $this->assertSame('database_db_main', Config::queue('databases'));
    }

    public function testJobsAttachPerQueueCaps(): void
    {
        $jobs = Config::jobs(['databases', 'functions']);

        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
        $this->assertSame(8, $jobs['functions']['maxCoroutines']);
        $this->assertSame('database_db_main', $jobs['databases']['queue']);
        $this->assertSame(Event::FUNCTIONS_QUEUE_NAME, $jobs['functions']['queue']);
    }
}
