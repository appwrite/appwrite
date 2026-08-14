<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Job;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class ServerJobsTest extends TestCase
{
    public function testJobRegistersIndependentCoroutineCaps(): void
    {
        $server = new Server(new RecordingAdapter());

        $functions = $server->job('v1-functions', 8);
        $databases = $server->job('database_db_main', 1);

        $this->assertInstanceOf(Job::class, $functions);
        $this->assertInstanceOf(Job::class, $databases);
        $this->assertNotSame($functions, $databases);
        $this->assertSame(8, $server->getJobCoroutines('v1-functions'));
        $this->assertSame(1, $server->getJobCoroutines('database_db_main'));
        $this->assertCount(2, $server->getJobs());
    }

    public function testOmittedCoroutineCapDefaultsToOne(): void
    {
        $server = new Server(new RecordingAdapter('v1-mails'));

        $server->job('v1-mails');

        $this->assertSame(1, $server->getJobCoroutines('v1-mails'));
    }

    public function testConsumeManyKeepsPerQueueCaps(): void
    {
        $adapter = new RecordingAdapter();

        $adapter->consumeMany(
            [
                [
                    'queue' => new Queue('database_db_main'),
                    'maxCoroutines' => 1,
                ],
                [
                    'queue' => new Queue('v1-functions'),
                    'maxCoroutines' => 8,
                ],
            ],
            static fn () => null,
            static fn () => null,
            static fn () => null,
        );

        $this->assertSame(
            [
                ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
            $adapter->consumed,
        );
    }
}
