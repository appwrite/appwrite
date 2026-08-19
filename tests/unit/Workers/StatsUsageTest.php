<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Platform\Workers\StatsResources;
use Appwrite\Platform\Workers\StatsUsage;
use Appwrite\Usage\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Tests\Unit\Usage\Fakes\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Usage\Usage;

final class StatsUsageTest extends TestCase
{
    public function testEventWorkerUsesResolvedTenantAndKeepsRealtimeDisconnect(): void
    {
        $adapter = new Adapter();
        $connection = new ReadyConnection(new Usage($adapter), $this->createStub(ClientInterface::class));
        $project = new Document(['$id' => 'project', '$sequence' => 42]);
        $message = $this->message([
            'project' => ['$id' => 'project', '$sequence' => 999],
            'metrics' => [
                ['key' => METRIC_NETWORK_REQUESTS, 'value' => 1],
                ['key' => METRIC_REALTIME_CONNECTIONS, 'value' => -1],
                ['key' => METRIC_NETWORK_OUTBOUND, 'value' => -5],
            ],
        ]);

        (new StatsUsage())->action($message, $project, $connection);

        self::assertCount(1, $adapter->batches);
        self::assertSame(Usage::TYPE_EVENT, $adapter->batches[0]['type']);
        self::assertSame(['42', '42'], array_column($adapter->batches[0]['metrics'], 'tenant'));
        self::assertSame([1, -1], array_column($adapter->batches[0]['metrics'], 'value'));
    }

    public function testGaugeWorkerUsesResolvedTenantAndDimensions(): void
    {
        $adapter = new Adapter();
        $connection = new ReadyConnection(new Usage($adapter), $this->createStub(ClientInterface::class));
        $project = new Document(['$id' => 'project', '$sequence' => 42, 'database' => 'dsn']);
        $message = $this->message([
            'project' => ['$id' => 'project', '$sequence' => 999],
            'gauges' => [[
                'metric' => METRIC_FILES_STORAGE,
                'value' => 10,
                'resourceType' => 'bucket',
                'resourceId' => 'bucket',
            ]],
        ]);

        (new StatsResources())->action(
            $message,
            $project,
            $this->createStub(Database::class),
            $this->createStub(Database::class),
            $connection,
        );

        self::assertCount(1, $adapter->batches);
        self::assertSame(Usage::TYPE_GAUGE, $adapter->batches[0]['type']);
        self::assertSame('42', $adapter->batches[0]['metrics'][0]['tenant']);
        self::assertSame('bucket', $adapter->batches[0]['metrics'][0]['tags']['resourceType']);
        self::assertSame('bucket', $adapter->batches[0]['metrics'][0]['tags']['resourceId']);
    }

    public function testDisabledWorkersDoNotResolveUsage(): void
    {
        $connection = new DisabledConnection($this->createStub(ClientInterface::class));
        $project = new Document(['$id' => 'project', '$sequence' => 42, 'database' => 'dsn']);
        $message = $this->message([
            'project' => ['$id' => 'project', '$sequence' => 42],
            'metrics' => [['key' => METRIC_NETWORK_REQUESTS, 'value' => 1]],
        ]);

        (new StatsUsage())->action($message, $project, $connection);

        self::assertFalse($connection->usageResolved);
    }

    /** @param array<string, mixed> $payload */
    private function message(array $payload): Message
    {
        return new Message([
            'pid' => 'test',
            'queue' => 'test',
            'timestamp' => time(),
            'payload' => $payload,
        ]);
    }
}

final class ReadyConnection extends Connection
{
    public function __construct(private readonly Usage $fakeUsage, ClientInterface $client)
    {
        parent::__construct(true, '', $client);
    }

    public function getUsage(): Usage
    {
        return $this->fakeUsage;
    }

    public function isReady(): bool
    {
        return true;
    }
}

final class DisabledConnection extends Connection
{
    public bool $usageResolved = false;

    public function __construct(ClientInterface $client)
    {
        parent::__construct(false, '', $client);
    }

    public function getUsage(): Usage
    {
        $this->usageResolved = true;
        throw new \LogicException('Disabled worker resolved usage');
    }
}
