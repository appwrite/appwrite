<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\StatsResources;
use Appwrite\Event\Message\Usage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class UsageTest extends TestCase
{
    public function testLegacyUsagePayloadRemainsReadable(): void
    {
        $message = Usage::fromArray([
            'project' => ['$id' => 'project', '$sequence' => 42, 'database' => 'dsn'],
            'metrics' => [['key' => 'network.requests', 'value' => 1]],
            'reduce' => [],
        ]);

        $this->assertSame('project', $message->project->getId());
        $this->assertSame('42', $message->project->getSequence());
        $this->assertSame('network.requests', $message->metrics[0]['key']);
    }

    public function testUsageMetadataRoundTripsWithoutChangingEnvelope(): void
    {
        $message = new Usage(
            new Document(['$id' => 'project', '$sequence' => 42, 'database' => 'dsn']),
            [[
                'key' => 'network.requests',
                'value' => 1,
                'service' => 'storage',
                'resourceType' => 'bucket',
                'resourceId' => 'bucket',
            ]],
        );

        $payload = $message->toArray();
        $this->assertSame('storage', $payload['metrics'][0]['service']);
        $this->assertSame(['project', 'metrics', 'reduce'], array_keys($payload));
    }

    public function testLegacyAndDimensionedGaugePayloadsRemainReadable(): void
    {
        $legacy = StatsResources::fromArray([
            'project' => ['$id' => 'project', '$sequence' => 42],
            'gauges' => [['metric' => 'users', 'value' => 2]],
        ]);
        $dimensioned = StatsResources::fromArray([
            'project' => ['$id' => 'project', '$sequence' => 42],
            'gauges' => [[
                'metric' => 'files.storage',
                'value' => 10,
                'resourceType' => 'bucket',
                'resourceId' => 'bucket',
            ]],
        ]);

        $this->assertSame('users', $legacy->gauges[0]['metric']);
        $this->assertSame('bucket', $dimensioned->gauges[0]['resourceId']);
    }
}
