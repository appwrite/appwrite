<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Headers;
use Utopia\NATS\Message;
use Utopia\NATS\Request;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

final class RequestsTest extends TestCase
{
    public static function reconnect(): iterable
    {
        yield [false];
        yield [true];
    }

    #[DataProvider('reconnect')]
    public function testFailedPongFailsEveryPendingRequestAndDisconnects(bool $reconnect): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(allowReconnect: $reconnect, transportFactory: fn(): FakeTransport => $fake));
        $fake->onWrite = static function (string $wire, FakeTransport $fake): void {
            if (str_starts_with($wire, 'PUB ')) {
                $fake->pushInbound("PING\r\n");
            } elseif ($wire === "PONG\r\n") {
                throw new ConnectionException('PONG write failed');
            }
        };
        $results = [];
        $connection->requestBatch([new Request(subject: 'one'), new Request(subject: 'two')], static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
        });
        $this->assertCount(2, $results);
        $this->assertInstanceOf(ConnectionException::class, $results[0]);
        $this->assertSame($results[0], $results[1]);
        $this->assertFalse($connection->isConnected());
        $connection->close();
    }

    #[DataProvider('reconnect')]
    public function testClosingErrorDisconnectsBeforeThrowingCallback(bool $reconnect): void
    {
        $fake = new FakeTransport();
        $failure = new ConnectionException('Application callback failed');
        $connection = null;
        $connection = Connection::connect(new ConnectionOptions(
            allowReconnect: $reconnect,
            onError: function () use (&$connection, $failure): never {
                $this->assertFalse($connection->isConnected());
                throw $failure;
            },
            transportFactory: fn(): FakeTransport => $fake,
        ));
        $fake->onWrite = static function (string $wire, FakeTransport $fake): void {
            if (str_starts_with($wire, 'PUB ')) {
                $fake->pushInbound("-ERR 'Stale Connection'\r\n");
            }
        };
        $callbacks = 0;
        try {
            $connection->requestBatch([new Request('one'), new Request('two')], static function () use (&$callbacks): void {
                $callbacks++;
            });
            $this->fail('Expected the callback exception');
        } catch (\Throwable $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertFalse($connection->isConnected());
        $this->assertSame(0, $callbacks);
        $this->assertSame(1, substr_count($fake->written, 'PUB one '));
        $this->assertSame(1, substr_count($fake->written, 'PUB two '));
        $connection->close();
    }

    public static function bufferedReplies(): iterable
    {
        yield [false, false];
        yield [false, true];
        yield [true, false];
        yield [true, true];
    }

    #[DataProvider('bufferedReplies')]
    public function testBufferedPongAndRepliesAreReadBeforeKeepalive(bool $reconnect, bool $pongLast): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(
            allowReconnect: $reconnect,
            pingInterval: 0.0,
            maxPingsOut: 1,
            transportFactory: fn(): FakeTransport => $fake,
        ));
        $fake->answerPings = false;
        $subjects = [];
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use (&$subjects, $pongLast): void {
            if (str_starts_with($wire, 'PUB ')) {
                preg_match_all('/PUB work ([^ ]+) 0\r\n\r\n/', $wire, $matches);
                $subjects = $matches[1];
            } elseif ($wire === "PING\r\n") {
                // One transport read buffers +OK, the PONG, and both replies.
                $fake->pushInbound($pongLast ? "+OK\r\n" : "+OK\r\nPONG\r\n");
                foreach ($subjects as $subject) {
                    $fake->pushInbound("MSG {$subject} 1 2\r\nok\r\n");
                }
                if ($pongLast) {
                    $fake->pushInbound("PONG\r\n");
                }
            }
        };
        $results = [];
        $connection->requestBatch([new Request('work'), new Request('work')], static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
        });
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame('ok', $result->data);
        }
        $this->assertTrue($connection->isConnected());
        $this->assertSame(2, substr_count($fake->written, 'PUB work '));
        $this->assertSame('ok', $connection->request('work')->data);
        $connection->close();
    }

    public static function keepalive(): iterable
    {
        yield 'request, silent peer' => [false, false];
        yield 'batch, silent peer' => [true, false];
        yield 'request, failed ping' => [false, true];
        yield 'batch, failed ping' => [true, true];
    }

    #[DataProvider('keepalive')]
    public function testKeepaliveFailureDoesNotReplayRequests(bool $batch, bool $failedWrite): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(
            allowReconnect: true,
            maxReconnectAttempts: 1,
            pingInterval: 0.0,
            maxPingsOut: 1,
            transportFactory: fn(): FakeTransport => $fake,
        ));
        $fake->answerPings = false;
        $before = substr_count($fake->written, "PING\r\n");
        if ($failedWrite) {
            $fake->onWrite = static function (string $wire): void {
                if ($wire === "PING\r\n") {
                    throw new ConnectionException('PING write failed');
                }
            };
        }
        $outcomes = [];
        for ($attempt = 0; $attempt < ($failedWrite ? 1 : 2); $attempt++) {
            try {
                if ($batch) {
                    $connection->requestBatch([new Request('work')], static function (int $index, Message|\Throwable $result) use (&$outcomes): void {
                        $outcomes[] = $result;
                    }, 0.01);
                } else {
                    $connection->request('work', timeout: 0.01);
                }
            } catch (ConnectionException $error) {
                $outcomes[] = $error;
            }
        }
        $this->assertCount($failedWrite ? 1 : 2, $outcomes);
        $this->assertInstanceOf(ConnectionException::class, $outcomes[array_key_last($outcomes)]);
        $this->assertNotInstanceOf(TimeoutException::class, $outcomes[array_key_last($outcomes)]);
        $this->assertSame($before + 1, substr_count($fake->written, "PING\r\n"));
        $this->assertSame(1, substr_count($fake->written, 'PUB work '));
        $this->assertFalse($connection->isConnected());
        $connection->close();
    }

    public function testPipelinesRequestsAndRetainsOutOfOrderPartialReplies(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $groups = [];
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use (&$groups): void {
            preg_match_all('/PUB work\.(\d+) ([^ ]+) 0\r\n\r\n/', $wire, $matches, PREG_SET_ORDER);
            if ($matches === []) {
                return;
            }
            $groups[] = \count($matches);
            foreach (array_reverse($matches) as $match) {
                if ($match[1] !== '2') {
                    $fake->pushInbound("MSG {$match[2]} 1 1\r\n{$match[1]}\r\n");
                }
            }
        };
        $results = [];
        $connection->requestBatch([
            new Request(subject: 'work.1'), new Request(subject: 'work.2'), new Request(subject: 'work.3'),
        ], static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
        }, 0.01);
        $this->assertSame([3], $groups, 'all requests are sent before waiting for replies');
        $this->assertSame('1', $results[0]->data);
        $this->assertInstanceOf(TimeoutException::class, $results[1]);
        $this->assertSame('3', $results[2]->data);
        $this->assertSame([2, 0, 1], array_keys($results), 'successful replies arrive before the missing reply times out');
        $this->assertSame('1', $connection->request('work.1')->data);
        $connection->close();
    }

    public function testSubscriptionCallbackExceptionIsNotATransportFailure(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $failure = new ConnectionException('Application callback failed');
        $connection->subscribe('events', static fn() => throw $failure);
        $fake->onWrite = static function (string $wire, FakeTransport $fake): void {
            if (str_starts_with($wire, 'PUB ')) {
                $fake->pushInbound("MSG events 1 1\r\nx\r\n");
            }
        };
        $called = false;
        try {
            $connection->requestBatch([new Request('work')], static function () use (&$called): void {
                $called = true;
            });
            self::fail('Application exception must propagate');
        } catch (ConnectionException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertFalse($called);
        $this->assertTrue($connection->isConnected());
        $connection->close();
    }

    public function testSingleAndBatchRequestsShareSubjectValidation(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $before = $fake->written;
        $refused = 0;
        foreach (['', 'bad subject', 'work.*', 'work..x'] as $subject) {
            foreach ([fn(): \Utopia\NATS\Request => new Request($subject), fn(): \Utopia\NATS\Message => $connection->request($subject)] as $create) {
                try {
                    $create();
                    self::fail('Invalid subject must be refused');
                } catch (\InvalidArgumentException) {
                    $refused++;
                }
            }
        }
        $this->assertSame(8, $refused);
        $this->assertSame($before, $fake->written);
        $connection->close();
    }

    public function testAmbiguousWriteIsNotReplayedAndDisconnects(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $writes = 0;
        $fake->onWrite = static function (string $wire) use (&$writes): void {
            if (str_starts_with($wire, 'PUB ')) {
                $writes++;
                throw new ConnectionException('Lost connection after write');
            }
        };
        $results = [];
        $connection->requestBatch([new Request(subject: 'first'), new Request(subject: 'second')], static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
        });
        $this->assertSame(1, $writes);
        $this->assertInstanceOf(ConnectionException::class, $results[0]);
        $this->assertInstanceOf(ConnectionException::class, $results[1]);
        $this->assertFalse($connection->isConnected());
        $connection->close();
    }

    public function testCallbackFailureAbortsAndLeavesConnectionUsable(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(subPendingMsgsLimit: 1, onSlowConsumer: static fn() => self::fail('Late replies must not accumulate'), transportFactory: fn(): FakeTransport => $fake));
        $this->respond($fake);
        $seen = [];
        // Even transport-shaped callback errors must not become request failures.
        $failure = new ConnectionException('Callback failed');
        try {
            $connection->requestBatch(array_fill(0, 4, new Request(subject: 'work')), static function (int $index, Message|\Throwable $result) use (&$seen, $failure): never {
                $seen[$index] = $result;
                throw $failure;
            });
            self::fail('Callback error must be surfaced');
        } catch (ConnectionException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame([0], array_keys($seen));
        $this->assertSame('ok', $connection->request('work')->data);
        $connection->close();
    }

    public function testHeadersAndNoRespondersRetainIndependentOutcomes(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $observed = '';
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use (&$observed): void {
            if (preg_match('/HPUB work ([^ ]+) (\d+) (\d+)\r\n/', $wire, $match)) {
                $observed = $wire;
                $fake->pushInbound("MSG {$match[1]} 1 2\r\nok\r\n");
            }
            if (preg_match('/PUB missing ([^ ]+) 0\r\n/', $wire, $match)) {
                $header = "NATS/1.0 503\r\n\r\n";
                $length = \strlen($header);
                $fake->pushInbound("HMSG {$match[1]} 1 {$length} {$length}\r\n{$header}\r\n");
            }
        };
        $headers = new Headers();
        $headers->set('Trace', 'example');
        $results = [];
        $connection->requestBatch([
            new Request(subject: 'work', data: 'payload', headers: $headers),
            new Request(subject: 'missing'),
        ], static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
        });
        $this->assertStringContainsString("Trace: example\r\n", $observed);
        $this->assertStringContainsString("\r\npayload\r\n", $observed);
        $this->assertSame('ok', $results[0]->data);
        $this->assertInstanceOf(NatsException::class, $results[1]);
        $connection->close();
    }

    public static function invalid(): iterable
    {
        yield 'array instead of Request' => [[['subject' => 'work']], 1.0];
        yield 'not a list' => [['key' => new Request('work')], 1.0];
        yield 'timeout' => [[new Request('work')], 0.0];
        yield 'infinite timeout' => [[new Request('work')], INF];
        yield 'invalid later request' => [[new Request('work'), null], 1.0];
    }

    #[DataProvider('invalid')]
    public function testInvalidInputDoesNotWriteOrInvokeCallbacks(array $requests, float $timeout): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $before = $fake->written;
        $called = false;
        try {
            $connection->requestBatch($requests, static function () use (&$called): void {
                $called = true;
            }, $timeout);
            self::fail('Invalid input must throw');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame($before, $fake->written);
        $this->assertFalse($called);
        $connection->close();
    }

    public function testEmptyInputNeedsNoConnection(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $connection->close();
        $before = $fake->written;
        $connection->requestBatch([], static fn() => self::fail('Empty batch must not invoke callback'));
        $this->assertSame($before, $fake->written);
    }

    public function testOversizedLaterRequestDoesNotPublishEarlierRequests(): void
    {
        $fake = new FakeTransport(['max_payload' => 16]);
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        try {
            $connection->requestBatch([
                new Request(subject: 'work'), new Request(subject: 'work', data: str_repeat('x', 17)),
            ], static fn() => self::fail('Validation must not invoke callback'));
            self::fail('Payload validation must throw');
        } catch (MaxPayloadException) {
        }
        $this->assertStringNotContainsString('PUB ', $fake->written);
        $this->respond($fake);
        $this->assertSame('ok', $connection->request('work')->data);
        $connection->close();
    }

    public function testCallbackCannotReadConnectionReentrantly(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $this->respond($fake);
        $refused = 0;
        $connection->requestBatch([new Request(subject: 'work')], function () use ($connection, &$refused): void {
            foreach ([fn(): \Utopia\NATS\Message => $connection->request('work'), $connection->processMessage(...), fn(): array => $connection->requestMany('work'), fn() => $connection->wait(1), $connection->flush(...), $connection->tick(...), $connection->drain(...), fn() => $connection->requestBatch([], static fn() => null)] as $read) {
                try {
                    $read();
                    self::fail('Nested read must be refused');
                } catch (\LogicException) {
                    $refused++;
                }
            }
        });
        $this->assertSame(8, $refused);
        $this->assertSame('ok', $connection->request('work')->data);
        $connection->close();
    }

    public function testSharedDeadlineStartsAfterWriteAndIncludesCallbacks(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $this->respond($fake);
        $respond = $fake->onWrite;
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use ($respond): void {
            usleep(30_000);
            $respond($wire, $fake);
        };
        $results = [];
        $connection->requestBatch(array_fill(0, 2, new Request(subject: 'work')), static function (int $index, Message|\Throwable $result) use (&$results): void {
            $results[$index] = $result;
            if ($index === 0) {
                usleep(30_000);
            }
        }, 0.01);
        $this->assertSame('ok', $results[0]->data, 'writing does not consume the response timeout');
        $this->assertInstanceOf(TimeoutException::class, $results[1], 'callbacks do not reset the shared deadline');
        $connection->close();
    }

    private function respond(FakeTransport $fake): void
    {
        $fake->onWrite = static function (string $wire, FakeTransport $fake): void {
            preg_match_all('/PUB work ([^ ]+) 0\r\n\r\n/', $wire, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $fake->pushInbound("MSG {$match[1]} 1 2\r\nok\r\n");
            }
        };
    }
}
