<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\TimeoutException;
use Utopia\SMTP\Tests\Unit\Support\FakeTransport;
use Utopia\SMTP\Timeouts;
use Utopia\SMTP\Transport\Native;

final class TimeoutsTest extends TestCase
{
    public function testReachingAServerIsGivenLessPatienceThanHearingBack(): void
    {
        // A host that is down should be noticed quickly; a reply to a message
        // may wait on the server scanning it.
        $timeouts = new Timeouts();

        $this->assertLessThan($timeouts->read, $timeouts->connect);
    }

    public function testEachWaitIsSetSeparately(): void
    {
        $timeouts = new Timeouts(connect: 1.5, read: 90.0, write: 45.0);

        $this->assertEqualsWithDelta(1.5, $timeouts->connect, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(90.0, $timeouts->read, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(45.0, $timeouts->write, PHP_FLOAT_EPSILON);
    }

    public function testRefusesATimeoutThatWouldNeverWait(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Timeouts(connect: 0.0);
    }

    public function testRefusesATimeoutBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Timeouts(read: -1.0);
    }

    public function testRefusesAWaitWithNoEnd(): void
    {
        // INF is greater than zero, so asking whether the number is positive
        // lets it through and the socket waits for ever.
        $this->expectException(InvalidArgumentException::class);

        new Timeouts(read: INF);
    }

    public function testRefusesANumberThatIsNotOne(): void
    {
        // NAN fails every comparison, including the one that was meant to
        // catch it, and reaches the socket as a deadline of nothing.
        $this->expectException(InvalidArgumentException::class);

        new Timeouts(write: NAN);
    }

    public function testTheClientAsksForEachWaitByName(): void
    {
        $transport = new class extends FakeTransport {
            /** @var list<string> */
            public array $waits = [];

            public function connect(float $timeout, bool $tls): void
            {
                $this->waits[] = "connect:{$timeout}";

                parent::connect($timeout, $tls);
            }

            public function read(int $length, float $timeout): string
            {
                $this->waits[] = "read:{$timeout}";

                return parent::read($length, $timeout);
            }

            public function write(string $data, float $timeout): void
            {
                $this->waits[] = "write:{$timeout}";

                parent::write($data, $timeout);
            }
        };

        $transport->reply(
            '220 mail.example.test',
            '250 mail.example.test',
            '250 Sender ok',
            '250 Recipient ok',
            '354 Go ahead',
            '250 Ok',
        );

        $client = new Client(
            $transport,
            encryption: Encryption::None,
            timeouts: new Timeouts(connect: 1.0, read: 2.0, write: 3.0),
        );

        $client->sendRaw(new Envelope('jane@example.test', ['john@example.test']), 'Body');

        $this->assertContains('connect:1', $transport->waits);
        $this->assertContains('read:2', $transport->waits);
        $this->assertContains('write:3', $transport->waits);
    }

    public function testAServerThatNeverAnswersTimesOut(): void
    {
        // A listener that accepts and then says nothing. No unroutable address
        // to depend on: the silence is ours to arrange.
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        $this->assertIsResource($server);

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        $transport = new Native('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1));
        $transport->connect(2.0, false);

        $peer = stream_socket_accept($server, 2);
        $started = microtime(true);

        try {
            $transport->read(8192, 0.25);
            $this->fail('Expected the read to time out');
        } catch (TimeoutException $exception) {
            $this->assertGreaterThanOrEqual(0.2, microtime(true) - $started);
            $this->assertStringContainsString('Timed out', $exception->getMessage());
        } finally {
            $transport->close();

            if (\is_resource($peer)) {
                fclose($peer);
            }

            fclose($server);
        }
    }

    public function testATimeoutIsToldApartFromAClosedConnection(): void
    {
        // Same read, different cause: one is worth trying again and the other
        // means the far end is gone.
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        $this->assertIsResource($server);

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        $transport = new Native('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1));
        $transport->connect(2.0, false);

        $peer = stream_socket_accept($server, 2);
        $this->assertIsResource($peer);
        fclose($peer);

        try {
            $transport->read(8192, 2.0);
            $this->fail('Expected the read to fail');
        } catch (TimeoutException) {
            $this->fail('A closed connection is not a timeout');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('closed', $exception->getMessage());
        } finally {
            $transport->close();
            fclose($server);
        }
    }
}
