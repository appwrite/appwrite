<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use PHPUnit\Framework\TestCase;
use Utopia\Database\DateTime;
use Utopia\System\System;
use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

/**
 * Drives the realtime pub/sub ingest end to end against Harness/server.php, a realtime
 * server with fake databases, to pin the permissionsChanged contract:
 *
 * - a revoked user's connection receives the revoking event with the roles it held
 *   until then, then the unauthorized error, then the close;
 * - a permission verification stuck on a half-open database connection, or failing
 *   outright, must not stall delivery to everyone else: the unverifiable user's
 *   connections are closed and the firehose keeps flowing.
 */
final class PermissionsChangedTest extends TestCase
{
    private const PROJECT = 'test';

    /** Matches _APP_REALTIME_PERMISSION_TIMEOUT passed to the harness. */
    private const PERMISSION_TIMEOUT = 2;

    /** @var resource|null */
    private $process = null;

    private int $port = 0;

    private string $log = '';

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole') || !\extension_loaded('redis')) {
            $this->markTestSkipped('Needs the swoole and redis extensions; run inside the appwrite container.');
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            // SIGTERM first so the Swoole master reaps its worker processes.
            \proc_terminate($this->process, 15);
            $deadline = \microtime(true) + 5;
            while (\microtime(true) < $deadline && \proc_get_status($this->process)['running']) {
                \usleep(100_000);
            }
            if (\proc_get_status($this->process)['running']) {
                \proc_terminate($this->process, 9);
            }
            \proc_close($this->process);
            $this->process = null;
        }

        if ($this->log !== '' && \file_exists($this->log)) {
            \unlink($this->log);
        }
    }

    private function startServer(bool $frozenBlocks): void
    {
        $this->port = \random_int(20000, 29999);
        $this->log = \sys_get_temp_dir() . '/realtime-harness-' . $this->port . '.log';

        $env = \array_merge(\getenv(), [
            'PORT' => (string) $this->port,
            '_APP_REALTIME_PERMISSION_TIMEOUT' => (string) self::PERMISSION_TIMEOUT,
            '_APP_OPTIONS_ABUSE' => 'disabled',
            '_APP_WORKERS_NUM' => '1',
            'TEST_FROZEN_BLOCKS' => $frozenBlocks ? 'true' : 'false',
        ]);

        $this->process = \proc_open(
            [PHP_BINARY, __DIR__ . '/Harness/server.php'],
            [1 => ['file', $this->log, 'w'], 2 => ['file', $this->log, 'a']],
            $pipes,
            null,
            $env
        );

        $this->assertIsResource($this->process, 'Failed to spawn the realtime harness');

        $deadline = \microtime(true) + 30;
        while (\microtime(true) < $deadline) {
            $socket = @\fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 1);
            if ($socket !== false) {
                \fclose($socket);

                return;
            }
            \usleep(200_000);
        }

        $this->fail('Realtime harness never listened on port ' . $this->port . ': ' . $this->tailLog());
    }

    private function tailLog(): string
    {
        $content = @\file_get_contents($this->log);

        return $content === false ? '(no log)' : \substr($content, -2000);
    }

    private function connect(string $userId, array $channels): WebSocketClient
    {
        $query = \http_build_query([
            'project' => self::PROJECT,
            'testUser' => $userId,
            'channels' => $channels,
        ]);

        $client = new WebSocketClient('ws://127.0.0.1:' . $this->port . '/v1/realtime?' . $query, ['timeout' => 10]);

        $connected = \json_decode($client->receive(), true);
        $this->assertSame('connected', $connected['type'] ?? null, 'Handshake failed: ' . $this->tailLog());

        return $client;
    }

    private function publish(array $event): void
    {
        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));

        $user = System::getEnv('_APP_REDIS_USER', '');
        $pass = System::getEnv('_APP_REDIS_PASS', '');
        if (!empty($pass)) {
            $redis->auth(empty($user) ? $pass : [$user, $pass]);
        }

        $redis->publish('realtime', \json_encode($event));
        $redis->close();
    }

    private function event(array $events, array $channels, array $roles, array $payload, bool $permissionsChanged = false, ?string $userId = null): array
    {
        return [
            'project' => self::PROJECT,
            'roles' => $roles,
            'permissionsChanged' => $permissionsChanged,
            'userId' => $userId,
            'data' => [
                'events' => $events,
                'channels' => $channels,
                'timestamp' => DateTime::formatTz(DateTime::now()),
                'payload' => $payload,
            ],
        ];
    }

    /**
     * Receives frames until one satisfies $matches, a bounded wait. Returns the frame,
     * or null when the connection closes or nothing matches within $seconds.
     */
    private function receiveMatch(WebSocketClient $client, callable $matches, int $seconds): ?array
    {
        $deadline = \microtime(true) + $seconds;
        $client->setTimeout(2);

        while (\microtime(true) < $deadline) {
            try {
                $frame = \json_decode((string) $client->receive(), true);
            } catch (TimeoutException) {
                continue;
            } catch (ConnectionException) {
                return null;
            }

            if (\is_array($frame) && $matches($frame)) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * The subscriber coroutine starts asynchronously, so events are published into the
     * void until it is live. Probes until a marker event arrives.
     */
    private function awaitSubscriber(WebSocketClient $client): void
    {
        $deadline = \microtime(true) + 30;
        $client->setTimeout(1);

        while (\microtime(true) < $deadline) {
            try {
                $this->publish($this->event(['probe'], ['documents'], ['users'], ['$id' => 'probe']));
            } catch (\Throwable) {
                \usleep(500_000);
                continue;
            }

            $frame = $this->receiveMatch($client, fn (array $frame): bool => ($frame['data']['payload']['$id'] ?? '') === 'probe', 2);
            if ($frame !== null) {
                return;
            }
        }

        $this->fail('Realtime subscriber never came up: ' . $this->tailLog());
    }

    private function isEvent(string $payloadId): callable
    {
        return fn (array $frame): bool => ($frame['type'] ?? '') === 'event'
            && ($frame['data']['payload']['$id'] ?? '') === $payloadId;
    }

    private function assertUnauthorizedClose(WebSocketClient $client, int $seconds): void
    {
        $error = $this->receiveMatch($client, fn (array $frame): bool => ($frame['type'] ?? '') === 'error', $seconds);
        $this->assertNotNull($error, 'Expected the unauthorized error frame before the close: ' . $this->tailLog());
        $this->assertSame(401, $error['data']['code'] ?? null);

        // Nothing but the close may follow the unauthorized frame. A close frame or a
        // dropped socket passes; silence until the deadline fails.
        $deadline = \microtime(true) + $seconds;
        $client->setTimeout(2);

        while (\microtime(true) < $deadline) {
            try {
                $frame = $client->receive();
            } catch (TimeoutException) {
                continue;
            } catch (ConnectionException) {
                return;
            }

            if ($frame === null) {
                return; // close frame
            }

            $this->fail('Expected only the close after the unauthorized frame, got: ' . $frame);
        }

        $this->fail('Expected the connection to close after the unauthorized frame');
    }

    public function testRevokedConnectionGetsEventThenUnauthorizedClose(): void
    {
        $this->startServer(frozenBlocks: false);

        $revoked = $this->connect('revoked', ['account', 'documents']);
        $active = $this->connect('active', ['account', 'documents']);
        $other = $this->connect('other', ['documents']);

        $this->awaitSubscriber($other);

        // Revocation: the fake database resolves `revoked` to a blocked user.
        $this->publish($this->event(
            events: ['users.revoked.update.status'],
            channels: ['account', 'account.revoked'],
            roles: ['user:revoked'],
            payload: ['$id' => 'revoked'],
            permissionsChanged: true,
            userId: 'revoked',
        ));

        // The revoking event is delivered with the roles held until now, then the close.
        $this->assertNotNull(
            $this->receiveMatch($revoked, $this->isEvent('revoked'), 10),
            'Revoked connection must receive the revoking event before the close: ' . $this->tailLog()
        );
        $this->assertUnauthorizedClose($revoked, 10);

        // A permissionsChanged event for a user who is still active rebuilds and keeps the connection.
        $this->publish($this->event(
            events: ['users.active.update.email'],
            channels: ['account', 'account.active'],
            roles: ['user:active'],
            payload: ['$id' => 'active'],
            permissionsChanged: true,
            userId: 'active',
        ));
        $this->assertNotNull(
            $this->receiveMatch($active, $this->isEvent('active'), 10),
            'Active connection must receive its permissionsChanged event: ' . $this->tailLog()
        );

        // The subscriber is still delivering to everyone after both.
        $this->publish($this->event(['documents.x.create'], ['documents'], ['users'], ['$id' => 'follow-up']));
        $this->assertNotNull(
            $this->receiveMatch($other, $this->isEvent('follow-up'), 10),
            'Follow-up event was not delivered: ' . $this->tailLog()
        );
        $this->assertNotNull(
            $this->receiveMatch($active, $this->isEvent('follow-up'), 10),
            'Rebuilt connection must keep receiving after its roles were refreshed: ' . $this->tailLog()
        );
    }

    public function testStuckVerificationFailsClosedAndKeepsDelivering(): void
    {
        $this->startServer(frozenBlocks: true);

        $frozen = $this->connect('frozen', ['account', 'documents']);
        $other = $this->connect('other', ['documents']);

        $this->awaitSubscriber($other);

        // The user lookup for `frozen` never returns, standing in for a half-open
        // database connection. Before the fix this parked the single subscriber
        // coroutine forever and nothing below was ever delivered.
        $this->publish($this->event(
            events: ['users.frozen.update.status'],
            channels: ['account', 'account.frozen'],
            roles: ['user:frozen'],
            payload: ['$id' => 'frozen'],
            permissionsChanged: true,
            userId: 'frozen',
        ));

        $this->publish($this->event(['documents.x.create'], ['documents'], ['users'], ['$id' => 'follow-up']));

        // Delivery to everyone else must survive the stuck verification.
        $this->assertNotNull(
            $this->receiveMatch($other, $this->isEvent('follow-up'), self::PERMISSION_TIMEOUT + 10),
            'Follow-up event was not delivered: a stuck permission verification stalled the firehose: ' . $this->tailLog()
        );

        // Fail closed: the unverifiable user still got the triggering event, then was closed.
        $this->assertNotNull(
            $this->receiveMatch($frozen, $this->isEvent('frozen'), 10),
            'Frozen connection must receive the triggering event before the close: ' . $this->tailLog()
        );
        $this->assertUnauthorizedClose($frozen, 10);

        // And the subscriber is still alive for the next event.
        $this->publish($this->event(['documents.y.create'], ['documents'], ['users'], ['$id' => 'after']));
        $this->assertNotNull(
            $this->receiveMatch($other, $this->isEvent('after'), 10),
            'Subscriber died after the timed-out verification: ' . $this->tailLog()
        );
    }

    public function testFailedVerificationFailsClosedAndKeepsDelivering(): void
    {
        $this->startServer(frozenBlocks: false);

        $broken = $this->connect('broken', ['account', 'documents']);
        $other = $this->connect('other', ['documents']);

        $this->awaitSubscriber($other);

        // The user lookup for `broken` throws: the user cannot be verified, so it must be
        // closed like a timeout, not kept open on its old roles.
        $this->publish($this->event(
            events: ['users.broken.update.status'],
            channels: ['account', 'account.broken', 'documents'],
            roles: ['users'],
            payload: ['$id' => 'broken'],
            permissionsChanged: true,
            userId: 'broken',
        ));

        // The triggering event still fans out to everyone else.
        $this->assertNotNull(
            $this->receiveMatch($other, $this->isEvent('broken'), 10),
            'Triggering event was not delivered to other subscribers: ' . $this->tailLog()
        );

        // Fail closed: the triggering event with the old roles, then the 401 and the close.
        $this->assertNotNull(
            $this->receiveMatch($broken, $this->isEvent('broken'), 10),
            'Broken connection must receive the triggering event before the close: ' . $this->tailLog()
        );
        $this->assertUnauthorizedClose($broken, 10);

        $this->publish($this->event(['documents.x.create'], ['documents'], ['users'], ['$id' => 'follow-up']));
        $this->assertNotNull(
            $this->receiveMatch($other, $this->isEvent('follow-up'), 10),
            'Follow-up event was not delivered after the failed verification: ' . $this->tailLog()
        );
    }
}
