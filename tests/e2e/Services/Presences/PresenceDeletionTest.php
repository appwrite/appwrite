<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

final class PresenceDeletionTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testUpdateDuringDeletion(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = \stream_socket_get_name($socket, false);
        $this->assertIsString($address);
        \fclose($socket);
        $port = \substr($address, \strrpos($address, ':') + 1);
        $log = \tempnam(\sys_get_temp_dir(), 'presence-http-');
        $this->assertIsString($log);

        $process = \proc_open(
            [PHP_BINARY, \dirname(__DIR__, 3) . '/resources/presences/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            \dirname(__DIR__, 4),
            [...\getenv(), 'PORT' => $port, '_APP_CPU_NUM' => '1', '_APP_WORKER_PER_CORE' => '1'],
        );
        $this->assertIsResource($process);

        try {
            $this->client->setEndpoint('http://127.0.0.1:' . $port . '/v1');
            $this->assertEventually(function () use ($log): void {
                $health = $this->client->call(Client::METHOD_GET, '/health/version', timeout: 1);
                $this->assertSame(200, $health['headers']['status-code'], (string) \file_get_contents($log));
            }, 15000, 100);

            $headers = [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ...$this->getHeaders(),
            ];

            // Test for SUCCESS: normal updates keep their API response contract.
            $created = $this->client->call(Client::METHOD_PUT, '/presences/' . ID::unique(), $headers, ['status' => 'online']);
            $this->assertSame(200, $created['headers']['status-code']);
            $presenceId = $created['body']['$id'];
            $updated = $this->client->call(Client::METHOD_PATCH, '/presences/' . $presenceId, $headers, ['status' => 'away']);
            $this->assertSame(200, $updated['headers']['status-code']);
            $this->assertSame($presenceId, $updated['body']['$id']);
            $this->assertSame('away', $updated['body']['status']);
            $this->assertIsArray($updated['body']['$permissions']);

            // Test for FAILURE: both initially absent and concurrently deleted
            // rows pass through the real HTTP error handler and JSON serializer.
            $missing = $this->client->call(Client::METHOD_PATCH, '/presences/' . ID::unique(), $headers, ['status' => 'away']);
            $this->assertSame(404, $missing['headers']['status-code']);
            $this->assertSame('presence_not_found', $missing['body']['type']);

            $deleted = $this->client->call(Client::METHOD_PATCH, '/presences/' . $presenceId, [
                ...$headers,
                'x-appwrite-test-presence-delete' => $presenceId,
            ], ['status' => 'busy']);
            $this->assertSame(404, $deleted['headers']['status-code']);
            $this->assertSame(404, $deleted['body']['code']);
            $this->assertSame('presence_not_found', $deleted['body']['type']);
            $this->assertStringContainsString('application/json', (string) $deleted['headers']['content-type']);

            $remaining = $this->client->call(Client::METHOD_GET, '/presences/' . $presenceId, $headers);
            $this->assertSame(404, $remaining['headers']['status-code']);
            $this->assertSame('presence_not_found', $remaining['body']['type']);
        } finally {
            \proc_terminate($process);
            \proc_close($process);
            \unlink($log);
        }
    }
}
