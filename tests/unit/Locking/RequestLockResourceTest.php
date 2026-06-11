<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use Appwrite\Locking\Lock;
use Appwrite\Locking\PlatformLock;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class RequestLockResourceTest extends TestCase
{
    public function test_request_resources_wire_platform_lock_to_lock_pool(): void
    {
        if (! \class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension is required to exercise the registered lock resource.');
        }

        $pool = new Pool(new Stack(), 'lock', 1, fn () => new \Redis());
        $pools = new RecordingPoolGroup();
        $pools->add($pool);

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('projects', 'p1', $this->callback(fn (Document $document): bool => $document->getArrayCopy() === [
                'accessedAt' => '2026-06-11 10:00:00',
            ]))
            ->willReturnArgument(2);

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
            ->set('dbForPlatform', fn () => $db);

        $lock = $context->get('lock');
        $platformLock = $context->get('platformLock');

        $this->assertInstanceOf(Lock::class, $lock);
        $this->assertInstanceOf(PlatformLock::class, $platformLock);

        $platformLock->set('projects', 'p1', 'accessedAt', '2026-06-11 10:00:00');

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
