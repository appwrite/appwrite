<?php

declare(strict_types=1);

namespace Utopia\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class InitWorkerTest extends TestCase
{
    public function testCombinedWorkersKeepIndependentCoroutineCaps(): void
    {
        $platform = $this->platform();
        $server = new Server(new RecordingAdapter());
        $platform->setWorker($server);

        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'all',
            'workers' => ['all'],
            'jobs' => [
                'databases' => ['queue' => 'database_db_main', 'coroutines' => 1, 'prefetch' => 100],
                'functions' => ['queue' => 'v1-functions', 'coroutines' => 8],
            ],
        ]);

        $this->assertCount(2, $server->jobs());
        $this->assertSame(1, $server->coroutines('database_db_main'));
        $this->assertSame(100, $server->prefetch('database_db_main'));
        $this->assertSame(8, $server->coroutines('v1-functions'));
        $this->assertSame(8, $server->prefetch('v1-functions'));
    }

    public function testSingleWorkerNamePathStillRegistersOneJob(): void
    {
        $platform = $this->platform();
        $server = new Server(new RecordingAdapter());
        $platform->setWorker($server);

        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'functions',
            'jobs' => [
                'functions' => ['queue' => 'v1-functions', 'coroutines' => 8],
            ],
        ]);

        $this->assertCount(1, $server->jobs());
        $this->assertSame(8, $server->coroutines('v1-functions'));
        $this->assertSame(1, $server->coroutines('database_db_main'));
    }

    private function platform(): Platform
    {
        $service = new class extends Service {
            public function __construct()
            {
                $this->type = Service::TYPE_WORKER;
                $this->addAction('databases', new class extends Action {
                    public function __construct()
                    {
                        $this->callback(static fn(): null => null);
                    }
                });
                $this->addAction('functions', new class extends Action {
                    public function __construct()
                    {
                        $this->callback(static fn(): null => null);
                    }
                });
            }
        };

        $module = new class ($service) extends Module {
            public function __construct(Service $service)
            {
                $this->addService('workers', $service);
            }
        };

        return new class ($module) extends Platform {};
    }
}

final class FakeConsumer implements Consumer
{
    // Initialization never receives; never is compatible with both queue contracts.
    public function receive(Queue $queue, int $timeout, int $n = 1): never
    {
        throw new \LogicException('Worker initialization must not receive messages');
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class RecordingAdapter extends Adapter
{
    public function __construct(string $namespace = 'utopia-queue')
    {
        parent::__construct(static fn(string $q): Consumer => new FakeConsumer(), 1, $namespace);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }
}
