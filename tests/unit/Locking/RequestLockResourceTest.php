<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Locking\Lock;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class RequestLockResourceTest extends TestCase
{
    public function test_request_resources_wire_lock_to_lock_pool(): void
    {
        if (! \class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension is required to exercise the registered lock resource.');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(1);

        $pool = new Pool(new Stack(), 'lock', 1, fn () => $redis);
        $pools = new RecordingPoolGroup();
        $pools->add($pool);

        $context = new Container();
        $registrar = require __DIR__.'/../../../app/init/resources/request.php';
        $registrar($context);

        $context
            ->set('pools', fn () => $pools)
            ->set('telemetry', fn () => new NoTelemetry())
            ->set('logger', fn () => null)
            ->set('project', fn () => new Document([
                '$id' => 'test-project',
                '$sequence' => '42',
            ]))
            ->set('dbForPlatform', fn () => null);

        $lock = $context->get('lock');

        $this->assertInstanceOf(Lock::class, $lock);

        $ran = false;
        $lock->withKey(
            $lock->key('projects', 'p1', 'accessedAt'),
            function () use (&$ran): void {
                $ran = true;
            },
            target: 'projects'
        );

        $this->assertTrue($ran);
        $this->assertSame(['lock'], $pools->names);
    }
}

final class RecordingPoolGroup extends Group
{
    /**
     * @var list<string>
     */
    public array $names = [];

    public function get(string $name): Pool
    {
        $this->names[] = $name;

        return parent::get($name);
    }
}
