<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Service;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Connection\Redis as Connection;

final class WorkerServicesTest extends TestCase
{
    private TestPlatform $platform;

    public function setUp(): void
    {
        $this->platform = new TestPlatform();
        $this->platform->init(Service::TYPE_WORKER, [
            'consumer' => new Broker(new Connection('localhost'), new Connection('localhost')),
            'workersNum' => 1,
            'workerName' => 'test',
            'queueName' => 'tests',
        ]);
    }

    public function testWorkerStartActionBoundToWorkerStartHook(): void
    {
        $hooks = $this->platform->getWorker()->getWorkerStart();
        $this->assertCount(1, $hooks);

        $action = $this->platform->getService('testWorker')->getAction('workerStartHook');
        $this->assertInstanceOf(TestActionWorkerStart::class, $action);
        $this->assertFalse($action->invoked);

        $hooks[0]->getAction()();

        $this->assertTrue($action->invoked, 'TYPE_WORKER_START action must be invoked through the workerStart hook');
    }

    public function testWorkerStopActionBoundToWorkerStopHook(): void
    {
        $hooks = $this->platform->getWorker()->getWorkerStop();
        $this->assertCount(1, $hooks);

        $action = $this->platform->getService('testWorker')->getAction('workerStopHook');
        $this->assertInstanceOf(TestActionWorkerStop::class, $action);
        $this->assertFalse($action->invoked);

        $hooks[0]->getAction()();

        $this->assertTrue($action->invoked, 'TYPE_WORKER_STOP action must be invoked through the workerStop hook');
    }
}
