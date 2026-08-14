<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Platform;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Queue\RecordingAdapter;
use Utopia\Platform\Action;
use Utopia\Platform\Module;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;
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
            'workerNames' => ['all'],
            'workerJobs' => [
                'databases' => ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                'functions' => ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
        ]);

        $this->assertCount(2, $server->getJobs());
        $this->assertSame(1, $server->getJobCoroutines('database_db_main'));
        $this->assertSame(8, $server->getJobCoroutines('v1-functions'));
    }

    public function testSingleWorkerNamePathStillRegistersOneJob(): void
    {
        $platform = $this->platform();
        $server = new Server(new RecordingAdapter('v1-functions'));
        $platform->setWorker($server);

        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'functions',
            'workerJobs' => [
                'functions' => ['queue' => 'v1-functions', 'maxCoroutines' => 8],
            ],
        ]);

        $this->assertCount(1, $server->getJobs());
        $this->assertSame(8, $server->getJobCoroutines('v1-functions'));
        $this->assertSame(1, $server->getJobCoroutines('database_db_main'));
    }

    private function platform(): Platform
    {
        $service = new class () extends Service {
            public function __construct()
            {
                $this->type = Service::TYPE_WORKER;
                $this->addAction('databases', new class () extends Action {
                    public function __construct()
                    {
                        $this->callback(static fn () => null);
                    }
                });
                $this->addAction('functions', new class () extends Action {
                    public function __construct()
                    {
                        $this->callback(static fn () => null);
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
