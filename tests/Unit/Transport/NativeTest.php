<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Transport\Native;

/**
 * Against a real socket, in process. The end-to-end tier covers this transport
 * too, but only with a container running — and the failures worth knowing about
 * are the ones a healthy server never produces.
 */
final class NativeTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private ?Native $transport = null;

    /** @var resource|null */
    private $peer;

    protected function tearDown(): void
    {
        $this->transport?->close();

        foreach ([$this->peer, $this->server] as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->peer = null;
        $this->server = null;
        $this->transport = null;
    }

    /**
     * A listener on an ephemeral port, and a transport connected to it. The
     * connection completes through the backlog, so accepting afterwards is not
     * a race even on one thread.
     */
    private function connect(): Native
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);

        if ($server === false) {
            $this->fail("Could not listen: {$error}");
        }

        $this->server = $server;

        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $this->transport = new Native('127.0.0.1', $port);
        $this->transport->connect(2.0, false);

        $peer = stream_socket_accept($server, 2);

        if ($peer === false) {
            $this->fail('The listener never saw the connection');
        }

        $this->peer = $peer;

        return $this->transport;
    }

    public function testWritesReachTheOtherEnd(): void
    {
        $transport = $this->connect();
        $transport->write("EHLO tests\r\n", 2.0);

        $this->assertSame("EHLO tests\r\n", fgets($this->peer()));
    }

    public function testReadsWhatTheOtherEndSent(): void
    {
        $transport = $this->connect();
        fwrite($this->peer(), "220 ready\r\n");

        $this->assertSame('220 ready', rtrim($transport->read(8192, 2.0), "\r\n"));
    }

    public function testCarriesAPayloadLargerThanOneWrite(): void
    {
        $transport = $this->connect();
        $payload = str_repeat('abcdefgh', 4096); // 32 KiB

        $transport->write($payload, 2.0);

        $read = '';

        while (\strlen($read) < \strlen($payload)) {
            $chunk = fread($this->peer(), 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        $this->assertSame($payload, $read, 'every byte has to arrive, in order');
    }

    public function testReadFailsWhenTheServerHangsUp(): void
    {
        $transport = $this->connect();
        fclose($this->peer());
        $this->peer = null;

        $this->expectException(ConnectionException::class);

        $transport->read(8192, 2.0);
    }

    public function testReadRejectsANonPositiveLength(): void
    {
        $transport = $this->connect();

        $this->expectException(ConnectionException::class);

        $transport->read(0, 2.0);
    }

    public function testReadingBeforeConnectingFails(): void
    {
        $transport = new Native('127.0.0.1', 1);

        $this->expectException(ConnectionException::class);

        $transport->read(8192, 2.0);
    }

    public function testReadingAfterCloseFails(): void
    {
        $transport = $this->connect();
        $transport->close();

        $this->expectException(ConnectionException::class);

        $transport->read(8192, 2.0);
    }

    public function testConnectingToNobodyFails(): void
    {
        $this->expectException(ConnectionException::class);

        // Port 1 rather than an ephemeral one nobody happens to hold: on Linux
        // loopback, connecting to a free port inside the ephemeral range can
        // pair with the source port the kernel just handed us and succeed
        // against itself.
        (new Native('127.0.0.1', 1))->connect(2.0, false);
    }

    public function testAPlainConnectionDoesNotClaimToBeEncrypted(): void
    {
        $this->assertFalse($this->connect()->isTls());
    }

    public function testCloseIsSafeToRepeat(): void
    {
        $transport = $this->connect();

        $transport->close();
        $transport->close();

        $this->assertFalse($transport->isTls());
    }

    /**
     * @return resource
     */
    private function peer()
    {
        $this->assertIsResource($this->peer);

        return $this->peer;
    }
}
