<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Appwrite\Event\Event;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class WorkersTest extends TestCase
{
    public function testDatabasesCoroutineCapIsOne(): void
    {
        $workers = Config::getParam('workers');

        $this->assertSame(1, $workers['databases']['maxCoroutines']);
    }

    public function testFunctionsKeepEightCoroutines(): void
    {
        $workers = Config::getParam('workers');

        $this->assertSame(8, $workers['functions']['maxCoroutines']);
    }

    public function testQueueNamesMatchPublishers(): void
    {
        $workers = Config::getParam('workers');

        $this->assertSame(Event::FUNCTIONS_QUEUE_NAME, $workers['functions']['queue']);
        $this->assertSame(Event::MAILS_QUEUE_NAME, $workers['mails']['queue']);
        $this->assertSame(Event::DELETE_QUEUE_NAME, $workers['deletes']['queue']);
        $this->assertSame('database_db_main', $workers['databases']['queue']);
    }

    public function testCombinedTotalIsSumOfPerQueueCaps(): void
    {
        $workers = Config::getParam('workers');
        $sum = 0;
        foreach ($workers as $spec) {
            $sum += (int) ($spec['maxCoroutines'] ?? 1);
        }

        $this->assertSame(61, $sum);
    }
}
