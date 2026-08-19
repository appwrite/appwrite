<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ConnectionTest extends TestCase
{
    public function testDisabledConnectionIsHealthyWithoutClickHouse(): void
    {
        $connection = new Connection(false, '', new UnusedUsageClient());

        $this->assertSame([
            'healthy' => true,
            'enabled' => false,
            'schemaReady' => false,
            'status' => 'disabled',
        ], $connection->healthCheck());
    }

    public function testDisabledConnectionDoesNotCreateUsageClient(): void
    {
        $connection = new Connection(false, '', new UnusedUsageClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Usage statistics are disabled');
        $connection->getUsage();
    }
}

final class UnusedUsageClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('Disabled usage must not send HTTP requests');
    }
}
