<?php

namespace Utopia\Tests;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Client as SwooleClient;
use Swoole\Coroutine\Client as CoroutineClient;
use Utopia\Mongo\Auth;
use Utopia\Mongo\Client;
use Utopia\Mongo\Exception;

trait TransportDouble
{
    public bool $open = true;
    public array $connectionResults = [];
    public array $connects = [];
    public array $events = [];
    public array $receives = [];
    public array $sends = [];
    public array $writes = [];

    private function connectTransport(string $host, int $port, float $timeout, int $flags): bool
    {
        $this->events[] = ['connect', $host, $port, $timeout, $flags];
        $this->connects[] = [$host, $port, $timeout, $flags];
        $result = array_shift($this->connectionResults) ?? true;
        $this->open = $result;

        return $result;
    }

    /** @var list<float> */
    public array $receiveTimeouts = [];

    private function receiveTransport(float $timeout = 0.0): string|false
    {
        $this->events[] = ['receive'];
        $this->receiveTimeouts[] = $timeout;
        $receive = array_shift($this->receives) ?? ['result' => '', 'error' => 0];
        $this->errCode = $receive['error'];

        return $receive['result'];
    }

    private function sendTransport(string $data): int|false
    {
        $this->events[] = ['send', $data];
        $this->writes[] = $data;
        $send = array_shift($this->sends) ?? ['result' => strlen($data), 'error' => 0];
        $this->errCode = $send['error'];

        return $send['result'];
    }
}

final class SyncTransportDouble extends SwooleClient
{
    use TransportDouble;

    public array $closes = [];

    /** @var list<float> */
    public array $timeoutOptions = [];

    public function __construct()
    {
    }

    public function set(array $settings): bool
    {
        if (isset($settings['timeout'])) {
            $this->timeoutOptions[] = (float) $settings['timeout'];
        }

        return true;
    }

    public function close(bool $force = false): bool
    {
        $this->events[] = ['close', $force];
        $this->closes[] = [$force];
        $this->open = false;

        return true;
    }

    public function connect(string $host, int $port = 0, float $timeout = 0.5, int $sock_flag = 0): bool
    {
        return $this->connectTransport($host, $port, $timeout, $sock_flag);
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    public function recv(int $size = 65535, int $flag = 0): string|false
    {
        return $this->receiveTransport();
    }

    public function send(string $data, int $flag = 0): int|false
    {
        return $this->sendTransport($data);
    }
}

final class CoroutineTransportDouble extends CoroutineClient
{
    use TransportDouble;

    public int $closes = 0;

    public function __construct()
    {
    }

    public function close(): bool
    {
        $this->events[] = ['close'];
        $this->closes++;
        $this->open = false;

        return true;
    }

    public function connect(string $host, int $port = 0, float $timeout = 0.5, int $sock_flag = 0): bool
    {
        return $this->connectTransport($host, $port, $timeout, $sock_flag);
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    public function recv(float $timeout = 0): string|false
    {
        return $this->receiveTransport($timeout);
    }

    public function send(string $data, float $timeout = 0): int|false
    {
        return $this->sendTransport($data);
    }
}

final class AuthenticationDouble extends Auth
{
    public function __construct()
    {
    }

    public function continue($data): array
    {
        return [['ping' => 1], 'admin'];
    }

    public function start(): array
    {
        return [['ping' => 1], 'admin'];
    }
}

final class ReconnectingClient extends Client
{
    public SwooleClient|CoroutineClient $transport;

    public function connect(): self
    {
        $this->transport->connect('mongo', 27017, 30.0);
        $connection = new ReflectionProperty(Client::class, 'isConnected');
        $connection->setAccessible(true);
        $connection->setValue($this, true);

        return $this;
    }
}

final class ClientTest extends TestCase
{
    public function testConnectPassesConfiguredTimeout(): void
    {
        $transport = new SyncTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 7.5);
        $this->set($client, 'auth', new AuthenticationDouble());

        $client->connect();

        $this->assertSame([['mongo', 27017, 7.5, 0]], $transport->connects);
    }

    public function testConnectDialsAndHandshakesUnderTheConnectDeadline(): void
    {
        $transport = new SyncTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 7.5, connectTimeout: 1.5);
        $this->set($client, 'auth', new AuthenticationDouble());

        $client->connect();

        $this->assertSame(
            [['mongo', 27017, 1.5, 0]],
            $transport->connects,
            'The dial must use the connect deadline, not the steady-state receive timeout',
        );
        $this->assertNull(
            $this->get($client, 'handshakeDeadline'),
            'A completed handshake must restore the steady-state receive deadline',
        );
    }

    public function testHandshakeSilenceFailsAtTheConnectDeadline(): void
    {
        // Behind a proxy that accepts instantly while its backend is
        // unreachable, the dial is never what stalls — the first handshake
        // reply is. Without a separate connect deadline the handshake waited
        // out the full receive timeout, doubling every outage the pool's
        // recovery was trying to shorten.
        $transport = new SyncTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => '', 'error' => 0],
            ['result' => '', 'error' => 0],
            ['result' => '', 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 5.0, connectTimeout: 0.05);
        $this->set($client, 'auth', new AuthenticationDouble());

        $startedAt = microtime(true);
        try {
            $client->connect();
            $this->fail('A silent handshake must fail at the connect deadline');
        } catch (Exception $exception) {
            $this->assertSame(11601, $exception->getCode());
        }

        $this->assertLessThan(
            1.0,
            microtime(true) - $startedAt,
            'The silent handshake must fail at the connect deadline, not the receive timeout',
        );
        $this->assertNull(
            $this->get($client, 'handshakeDeadline'),
            'A failed handshake must not leave the connect deadline armed on a reused client',
        );
    }

    public function testDialAndHandshakeConsumeOneSharedConnectBudget(): void
    {
        // Each phase must receive the remainder of one absolute deadline — a
        // fresh allowance per phase lets the complete attempt take a multiple
        // of the configured bound (greptile P1 on #46).
        $transport = new SyncTransportDouble();
        $client = $this->client($transport, timeout: 5.0, connectTimeout: 0.2);
        $this->set($client, 'handshakeDeadline', microtime(true) + 0.2);

        $first = $this->invokeReceiveTimeout($client);
        usleep(100_000);
        $second = $this->invokeReceiveTimeout($client);

        $this->assertLessThan(0.21, $first);
        $this->assertLessThan(
            $first,
            $second,
            'Handshake receives must consume the shared connect budget, not restart it',
        );

        $this->set($client, 'handshakeDeadline', null);
        $this->assertSame(5.0, $this->invokeReceiveTimeout($client), 'Steady state must use the receive timeout');
    }

    public function testFailedHandshakeInvalidatesTheDialedSocket(): void
    {
        // A dialed-but-unauthenticated socket must never be reused: the
        // transport reports connected, so a later connect() would early-return
        // without ever completing authentication (CodeRabbit on #46).
        $transport = new SyncTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => $this->frame(['ok' => 0.0, 'errmsg' => 'Authentication failed', 'code' => 18]), 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 0.2);
        $this->set($client, 'auth', new AuthenticationDouble());

        try {
            $client->connect();
            $this->fail('A failed SCRAM exchange must throw');
        } catch (Exception) {
        }

        $this->assertNotSame([], $transport->closes, 'The half-authenticated transport must be closed');
        $this->assertFalse($this->get($client, 'isConnected'));

        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $transport->open = false;
        $client->connect();

        $this->assertCount(
            2,
            $transport->connects,
            'A connect() after a failed handshake must dial again, never reuse the unauthenticated socket',
        );
    }

    public function testSocketWaitsNeverExceedTheRemainingConnectBudget(): void
    {
        // The deadline arithmetic means nothing if the blocking primitive
        // itself waits on the steady-state timeout: a peer that accepts and
        // then goes silent parks the first recv() for that full window before
        // any deadline is rechecked (greptile P1 x3 on #46 — the root).
        $transport = new CoroutineTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 5.0, connectTimeout: 0.2);
        $this->set($client, 'auth', new AuthenticationDouble());

        $client->connect();

        $this->assertNotSame([], $transport->receiveTimeouts);
        foreach ($transport->receiveTimeouts as $wait) {
            $this->assertGreaterThan(0.0, $wait);
            $this->assertLessThanOrEqual(
                0.2,
                $wait,
                'A handshake socket wait must be bounded by the remaining connect budget, '
                . 'never the steady-state receive timeout',
            );
        }

        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $transport->receiveTimeouts = [];
        $client->query(['ping' => 1]);

        $this->assertNotSame([], $transport->receiveTimeouts);
        $this->assertEqualsWithDelta(
            5.0,
            $transport->receiveTimeouts[0],
            0.05,
            'Steady-state socket waits must use the full receive timeout again',
        );
    }

    public function testSyncSocketWaitsRefreshTheClientTimeoutOption(): void
    {
        // The synchronous client cannot take a per-call timeout; the budget
        // travels through the client options instead, refreshed per wait.
        $transport = new SyncTransportDouble();
        $transport->open = false;
        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->client($transport, timeout: 5.0, connectTimeout: 0.2);
        $this->set($client, 'auth', new AuthenticationDouble());

        $client->connect();

        $this->assertNotSame([], $transport->timeoutOptions);
        foreach ($transport->timeoutOptions as $wait) {
            $this->assertLessThanOrEqual(0.2, $wait);
        }
    }

    public function testSyncReceiveFailureHardClosesAndClearsState(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [['result' => false, 'error' => 11]];
        $client = $this->client($transport);
        $this->seedState($client);

        $exception = $this->receiveException($client);

        $this->assertSame(11601, $exception->getCode());
        $this->assertStringContainsString('errCode=11', $exception->getMessage());
        $this->assertSame([[true]], $transport->closes);
        $this->assertSame([], $transport->connects);
        $this->assertStateCleared($client);
    }

    public function testCoroutineReceiveFailureHardClosesAndClearsState(): void
    {
        $transport = new CoroutineTransportDouble();
        $transport->receives = [['result' => false, 'error' => 11]];
        $client = $this->client($transport);
        $this->seedState($client);

        $exception = $this->receiveException($client);

        $this->assertSame(11601, $exception->getCode());
        $this->assertStringContainsString('errCode=11', $exception->getMessage());
        $this->assertSame(1, $transport->closes);
        $this->assertSame([], $transport->connects);
        $this->assertStateCleared($client);
    }

    public function testTransientEmptyReceiveContinuesWithoutClosing(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [
            ['result' => '', 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->client($transport);

        $result = $this->receive($client);

        $this->assertSame(1.0, $result->ok);
        $this->assertSame([], $transport->closes);
    }

    public function testErroredEmptyReceiveHardClosesTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [['result' => '', 'error' => 11]];
        $client = $this->client($transport);
        $this->seedState($client);

        $exception = $this->receiveException($client);

        $this->assertSame(11601, $exception->getCode());
        $this->assertStringContainsString('errCode=11', $exception->getMessage());
        $this->assertSame([[true]], $transport->closes);
        $this->assertStateCleared($client);
    }

    public function testReceiveDeadlineHardClosesTransport(): void
    {
        $transport = new SyncTransportDouble();
        $client = $this->client($transport, timeout: 0.0001);

        $exception = $this->receiveException($client);

        $this->assertSame(11601, $exception->getCode());
        $this->assertSame([[true]], $transport->closes);
    }

    public function testSyncSendFailureHardClosesBeforeFreshFullMessageRetry(): void
    {
        $transport = new SyncTransportDouble();
        $transport->sends = [['result' => false, 'error' => 11]];
        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $client = $this->reconnectingClient($transport);
        $this->seedConnectionContext($client);

        $result = $client->send('complete-message');

        $this->assertSame(1.0, $result->ok);
        $this->assertSame(['complete-message', 'complete-message'], $transport->writes);
        $this->assertSame(
            ['send', 'close', 'connect', 'send', 'receive'],
            array_column($transport->events, 0)
        );
        $this->assertSame([[true]], $transport->closes);
        $this->assertSame([], $this->get($client, 'sessions'));
        $this->assertConnectionContextCleared($client);
        $this->assertTrue($this->get($client, 'isConnected'));
    }

    public function testSuccessfulSendRecoveryPreservesTransactionSessionForCommit(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [[
            'result' => $this->frame([
                'ok' => 1.0,
                'id' => ['id' => new Binary(str_repeat("\1", 16), Binary::TYPE_GENERIC)],
            ]),
            'error' => 0,
        ]];
        $client = $this->reconnectingClient($transport);
        $this->set($client, 'isConnected', true);
        $session = $client->startSession();
        $this->assertTrue($client->startTransaction($session));

        $transport->sends = [['result' => false, 'error' => 11]];
        $transport->receives = [
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];

        $operation = $client->query(['ping' => 1, 'session' => $session]);
        $commit = $client->commitTransaction($session);
        $sessions = $this->get($client, 'sessions');
        $ended = $client->endSessions([$session]);

        $this->assertSame(1.0, $operation->ok);
        $this->assertSame(1.0, $commit->ok);
        $this->assertSame(1.0, $ended->ok);
        $this->assertSame(Client::TRANSACTION_COMMITTED, $sessions[$session['sessionId']]['state']);
        $this->assertSame([], $this->get($client, 'sessions'));
    }

    public function testCoroutineSendFailureHardClosesBeforeFreshFullMessageRetry(): void
    {
        $transport = new CoroutineTransportDouble();
        $transport->sends = [['result' => false, 'error' => 11]];
        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $client = $this->reconnectingClient($transport);

        $result = $client->send('complete-message');

        $this->assertSame(1.0, $result->ok);
        $this->assertSame(['complete-message', 'complete-message'], $transport->writes);
        $this->assertSame(
            ['send', 'close', 'connect', 'send', 'receive'],
            array_column($transport->events, 0)
        );
        $this->assertSame(1, $transport->closes);
    }

    public function testSyncShortSendHardClosesBeforeRetry(): void
    {
        $transport = new SyncTransportDouble();
        $transport->sends = [['result' => 4, 'error' => 0]];
        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $client = $this->reconnectingClient($transport);

        $client->send('complete-message');

        $this->assertSame([[true]], $transport->closes);
        $this->assertSame(['complete-message', 'complete-message'], $transport->writes);
    }

    public function testCoroutineShortSendHardClosesBeforeRetry(): void
    {
        $transport = new CoroutineTransportDouble();
        $transport->sends = [['result' => 4, 'error' => 0]];
        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $client = $this->reconnectingClient($transport);

        $client->send('complete-message');

        $this->assertSame(1, $transport->closes);
        $this->assertSame(['complete-message', 'complete-message'], $transport->writes);
    }

    public function testRetryFailureHardClosesReplacementTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->sends = [
            ['result' => false, 'error' => 11],
            ['result' => false, 'error' => 0],
        ];
        $client = $this->reconnectingClient($transport);
        $this->seedState($client);

        try {
            $client->send('complete-message');
            $this->fail('Expected send failure');
        } catch (Exception $exception) {
            $this->assertSame(11, $exception->getCode());
        }

        $this->assertSame([[true], [true]], $transport->closes);
        $this->assertStateCleared($client);
    }

    public function testRetryShortSendHardClosesReplacementTransport(): void
    {
        $transport = new CoroutineTransportDouble();
        $transport->sends = [
            ['result' => false, 'error' => 11],
            ['result' => 4, 'error' => 0],
        ];
        $transport->receives = [['result' => $this->frame(['ok' => 1.0]), 'error' => 0]];
        $client = $this->reconnectingClient($transport);
        $this->seedState($client);

        try {
            $client->send('complete-message');
            $this->fail('Expected send failure');
        } catch (Exception $exception) {
            $this->assertSame(11, $exception->getCode());
        }

        $this->assertSame(2, $transport->closes);
        $this->assertStateCleared($client);
    }

    public function testFailedReconnectDoesNotRestoreSessions(): void
    {
        $transport = new SyncTransportDouble();
        $transport->sends = [['result' => false, 'error' => 11]];
        $transport->connectionResults = [false];
        $client = $this->client($transport);
        $this->seedState($client);

        try {
            $client->send('complete-message');
            $this->fail('Expected reconnect failure');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Failed to connect to MongoDB', $exception->getMessage());
        }

        $this->assertSame([[true], [true]], $transport->closes);
        $this->assertStateCleared($client);
    }

    public function testImpossibleFrameLengthHardClosesTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [['result' => pack('V', 20), 'error' => 0]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertStringContainsString('Invalid response length', $exception->getMessage());
        $this->assertSame([[true]], $transport->closes);
    }

    public function testOverlongFrameHardClosesTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [['result' => pack('V*', 21, 1, 0, 2013, 0) . "\0x", 'error' => 0]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertStringContainsString('Response length mismatch', $exception->getMessage());
        $this->assertSame([[true]], $transport->closes);
    }

    public function testInvalidBsonHardClosesCoroutineTransport(): void
    {
        $transport = new CoroutineTransportDouble();
        $transport->receives = [['result' => pack('V*', 26, 1, 0, 2013, 0) . "\0abcde", 'error' => 0]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertStringContainsString('BSON', $exception->getMessage());
        $this->assertSame(1, $transport->closes);
    }

    public function testOpReplyIsRejectedByOpMsgParser(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [[
            'result' => pack('V*', 36, 1, 0, 1, 0, 0, 0, 0, 0),
            'error' => 0,
        ]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertStringContainsString('Invalid response operation code: 1', $exception->getMessage());
        $this->assertSame([[true]], $transport->closes);
    }

    public function testDecodedMongoCommandErrorDoesNotCloseTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [[
            'result' => $this->frame([
                'ok' => 0.0,
                'errmsg' => 'duplicate key',
                'code' => 11000,
                'codeName' => 'DuplicateKey',
            ]),
            'error' => 0,
        ]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertSame(11000, $exception->getCode());
        $this->assertSame([], $transport->closes);
        $this->assertTrue($transport->open);
    }

    public function testPrimaryChangeErrorsCloseTransportAndPreserveSessions(): void
    {
        foreach ([189, 10107, 11602, 13435, 13436] as $code) {
            $transport = new SyncTransportDouble();
            $transport->receives = [[
                'result' => $this->frame([
                    'ok' => 0.0,
                    'errmsg' => 'primary changed',
                    'code' => $code,
                    'codeName' => 'PrimaryChanged',
                ]),
                'error' => 0,
            ]];
            $client = $this->client($transport);
            $this->seedState($client);
            $sessions = $this->get($client, 'sessions');

            $exception = $this->receiveException($client);

            try {
                $this->assertSame($code, $exception->getCode());
                $this->assertSame([[true]], $transport->closes);
                $this->assertSame($sessions, $this->get($client, 'sessions'));
                $this->assertFalse($this->get($client, 'isConnected'));
                $this->assertTrue($this->get($client, 'reconnect'));
                $this->assertConnectionContextCleared($client);
            } finally {
                $this->set($client, 'sessions', []);
            }
        }
    }

    public function testPrimaryChangeWriteErrorClosesTransportAndPreservesSessions(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [[
            'result' => $this->frame([
                'ok' => 1.0,
                'writeErrors' => [[
                    'errmsg' => 'not primary',
                    'code' => 10107,
                ]],
            ]),
            'error' => 0,
        ]];
        $client = $this->client($transport);
        $this->seedState($client);
        $sessions = $this->get($client, 'sessions');

        $exception = $this->receiveException($client);

        try {
            $this->assertSame(10107, $exception->getCode());
            $this->assertSame([[true]], $transport->closes);
            $this->assertSame($sessions, $this->get($client, 'sessions'));
            $this->assertFalse($this->get($client, 'isConnected'));
            $this->assertTrue($this->get($client, 'reconnect'));
            $this->assertConnectionContextCleared($client);
        } finally {
            $this->set($client, 'sessions', []);
        }
    }

    public function testPermanentWriteErrorDoesNotCloseTransport(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [[
            'result' => $this->frame([
                'ok' => 1.0,
                'writeErrors' => [[
                    'errmsg' => 'duplicate key',
                    'code' => 11000,
                ]],
            ]),
            'error' => 0,
        ]];
        $client = $this->client($transport);

        $exception = $this->receiveException($client);

        $this->assertSame(11000, $exception->getCode());
        $this->assertSame([], $transport->closes);
        $this->assertTrue($transport->open);
    }

    public function testTransactionRetriesOnAFreshTransportAfterPrimaryChange(): void
    {
        $transport = new SyncTransportDouble();
        $transport->receives = [
            [
                'result' => $this->frame([
                    'ok' => 0.0,
                    'errmsg' => 'not primary',
                    'code' => 10107,
                    'codeName' => 'NotWritablePrimary',
                ]),
                'error' => 0,
            ],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
            ['result' => $this->frame(['ok' => 1.0]), 'error' => 0],
        ];
        $client = $this->reconnectingClient($transport);
        $identifier = (object) ['id' => new Binary(str_repeat("\1", 16), Binary::TYPE_GENERIC)];
        $session = ['id' => $identifier, 'sessionId' => 'session'];
        $this->set($client, 'isConnected', true);
        $this->set($client, 'sessions', [
            'session' => [
                'id' => $identifier,
                'state' => Client::TRANSACTION_NONE,
                'txnNumber' => 0,
                'lastUse' => time(),
                'operationTime' => null,
                'clusterTime' => null,
                'options' => [],
                'retryableWriteNumber' => 0,
            ],
        ]);

        $result = $client->withTransaction(
            $session,
            fn (array $transaction): mixed => $client->query(['ping' => 1, 'session' => $transaction]),
            ['retryDelayMs' => 0],
        );
        $sessions = $this->get($client, 'sessions');

        $this->assertSame(1.0, $result->ok);
        $this->assertSame(Client::TRANSACTION_COMMITTED, $sessions['session']['state']);
        $this->assertSame(2, $sessions['session']['txnNumber']);
        $this->assertSame([[true]], $transport->closes);
        $this->assertCount(1, $transport->connects);
        $this->assertFalse($this->get($client, 'reconnect'));
        $this->set($client, 'sessions', []);
    }

    private function assertStateCleared(Client $client): void
    {
        $this->assertFalse($this->get($client, 'isConnected'));
        $this->assertContextCleared($client);
    }

    private function assertContextCleared(Client $client): void
    {
        $this->assertSame([], $this->get($client, 'sessions'));
        $this->assertConnectionContextCleared($client);
    }

    private function assertConnectionContextCleared(Client $client): void
    {
        $this->assertNull($this->get($client, 'clusterTime'));
        $this->assertNull($this->get($client, 'operationTime'));
        $this->assertNull($this->get($client, 'replicaSet'));
    }

    private function client(
        SwooleClient|CoroutineClient $transport,
        float $timeout = 0.05,
        ?float $connectTimeout = null,
    ): Client {
        $client = new Client(
            'testing',
            'mongo',
            27017,
            'root',
            'example',
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
        $this->set($client, 'client', $transport);

        return $client;
    }

    private function frame(array $document): string
    {
        $bson = (string) Document::fromPHP($document);

        return pack('V*', 21 + strlen($bson), 1, 0, 2013, 0) . "\0" . $bson;
    }

    private function get(Client $client, string $property): mixed
    {
        $reflection = new ReflectionProperty(Client::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($client);
    }

    private function receive(Client $client): mixed
    {
        $reflection = new ReflectionMethod(Client::class, 'receive');
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }

    private function invokeReceiveTimeout(Client $client): float
    {
        $reflection = new ReflectionMethod(Client::class, 'receiveTimeout');
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }

    private function receiveException(Client $client): Exception
    {
        try {
            $this->receive($client);
            $this->fail('Expected receive failure');
        } catch (Exception $exception) {
            return $exception;
        }
    }

    private function reconnectingClient(SwooleClient|CoroutineClient $transport): ReconnectingClient
    {
        $client = new ReconnectingClient('testing', 'mongo', 27017, 'root', 'example');
        $client->transport = $transport;
        $this->set($client, 'client', $transport);

        return $client;
    }

    private function seedState(Client $client): void
    {
        $this->seedConnectionContext($client);
        $this->set($client, 'sessions', [
            'session' => [
                'id' => (object) ['id' => 'session'],
                'state' => Client::TRANSACTION_NONE,
                'txnNumber' => 0,
                'lastUse' => time(),
                'operationTime' => null,
                'clusterTime' => null,
                'options' => [],
                'retryableWriteNumber' => 0,
            ],
        ]);
    }

    private function seedConnectionContext(Client $client): void
    {
        $this->set($client, 'isConnected', true);
        $this->set($client, 'clusterTime', (object) ['time' => 1]);
        $this->set($client, 'operationTime', (object) ['time' => 2]);
        $this->set($client, 'replicaSet', true);
    }

    private function set(Client $client, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(Client::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($client, $value);
    }
}
