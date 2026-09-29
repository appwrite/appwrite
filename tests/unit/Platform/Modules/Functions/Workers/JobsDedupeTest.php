<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Vcs\Factory as VcsFactory;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Storage\Device;

final class JobsDedupeTest extends TestCase
{
    private Cache $cache;

    /** @var list<string> */
    private array $written = [];

    private int $failures = 0;

    protected function setUp(): void
    {
        $this->cache = new Cache(new Memory());
    }

    public function testRedeliveryAppliesAnEventWhoseFirstAttemptFailed(): void
    {
        $this->failures = 1;

        try {
            $this->deliver('evt-1', 'npm install');
            $this->fail('the first delivery should have failed');
        } catch (\RuntimeException $error) {
            $this->assertSame('connection lost', $error->getMessage());
        }

        $this->deliver('evt-1', 'npm install');

        $this->assertSame(["npm install\n"], $this->written);
    }

    public function testCopyThatTimesOutOnTheLockKeepsTheMark(): void
    {
        $this->deliver('evt-1', 'npm install');

        try {
            $this->deliver('evt-1', 'npm install', lockTimesOut: true);
            $this->fail('the copy that timed out on the lock should have failed');
        } catch (\RuntimeException $error) {
            $this->assertSame('lock timeout', $error->getMessage());
        }

        $this->deliver('evt-1', 'npm install');

        $this->assertSame(["npm install\n"], $this->written);
    }

    public function testRepeatOfAnAppliedEventIsSkipped(): void
    {
        $this->deliver('evt-1', 'npm install');
        $this->deliver('evt-1', 'npm install');

        $this->assertSame(["npm install\n"], $this->written);
    }

    private function deliver(string $eventId, string $line, bool $lockTimesOut = false): void
    {
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturn(new Document([
            '$id' => 'dep-1',
            'status' => 'building',
            'resourceType' => 'functions',
            'resourceId' => 'func-1',
            'buildLogs' => '',
        ]));
        $dbForProject->method('updateDocuments')->willReturnCallback(function (string $collection, Document $update): int {
            if ($this->failures > 0) {
                $this->failures--;
                throw new \RuntimeException('connection lost');
            }
            $this->written[] = $update->getAttribute('buildLogs');

            return 1;
        });

        $message = new Message([
            'pid' => 'pid-' . \count($this->written),
            'queue' => 'v1-jobs',
            'timestamp' => \time(),
            'payload' => [
                'project' => ['$id' => 'project-1'],
                'id' => $eventId,
                'event' => 'orchestrator.job.log',
                'data' => [
                    'jobId' => 'job-1',
                    'lines' => [$line],
                    'stream' => 'stdout',
                    'meta' => ['deploymentId' => 'dep-1'],
                ],
            ],
        ]);

        (new Jobs())->action(
            $message,
            new Document(['$id' => 'project-1', '$sequence' => '7']),
            $dbForProject,
            $this->createStub(Database::class),
            $this->createStub(Realtime::class),
            $this->createStub(Event::class),
            $this->createStub(Webhook::class),
            $this->createStub(FunctionPublisher::class),
            $this->createStub(ScreenshotPublisher::class),
            $this->createStub(UsagePublisher::class),
            $this->createStub(UsageContext::class),
            $this->createStub(Device::class),
            $this->createStub(Device::class),
            $this->createStub(Device::class),
            $this->createStub(VcsFactory::class),
            $this->cache,
            fn (string $key, int $ttl, callable $callback, float $timeout = 0.0): mixed => $lockTimesOut
                ? throw new \RuntimeException('lock timeout')
                : $callback(),
            [],
            [],
            $this->createStub(Bus::class),
        );
    }
}
