<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TlsException;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;

final class TlsFailureTest extends TestCase
{
    public function testFailedTlsUpgradeRaisesTlsException(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not open a loopback listener: [{$errno}] {$errstr}");
        }
        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        // Something that is not a TLS server answers the ClientHello.
        $peer = stream_socket_accept($server, 1.0);
        $this->assertIsResource($peer);
        fwrite($peer, "HTTP/1.1 400 Bad Request\r\n\r\n");
        fclose($peer);

        try {
            $transport->upgradeTls(['verify_peer' => false, 'verify_peer_name' => false]);
            $this->fail('The upgrade must fail against a peer that does not speak TLS');
        } catch (TlsException $error) {
            $this->assertStringStartsWith('Failed to upgrade connection to TLS: ', $error->getMessage());
        } finally {
            $transport->close();
            fclose($server);
        }
    }

    public function testFailedTlsUpgradeClosesTheSocket(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not open a loopback listener: [{$errno}] {$errstr}");
        }
        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        $peer = stream_socket_accept($server, 1.0);
        $this->assertIsResource($peer);
        fwrite($peer, "HTTP/1.1 400 Bad Request\r\n\r\n");

        try {
            $transport->upgradeTls(['verify_peer' => false, 'verify_peer_name' => false]);
            $this->fail('The upgrade must fail against a peer that does not speak TLS');
        } catch (TlsException) {
        }

        $this->assertFalse($transport->isConnected());

        // The peer reads EOF once the client closes.
        stream_set_timeout($peer, 1);
        while (!feof($peer) && !stream_get_meta_data($peer)['timed_out']) {
            fread($peer, 8192);
        }
        $this->assertFalse(stream_get_meta_data($peer)['timed_out'], 'the client left the socket open');
        $this->assertTrue(feof($peer));

        fclose($peer);
        fclose($server);
    }

    public function testRefusedTlsConnectIsNotATlsFailure(): void
    {
        // A port that was just listening and no longer is refuses the connect.
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not open a loopback listener: [{$errno}] {$errstr}");
        }
        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));
        fclose($server);

        $transport = new TlsTransport();

        try {
            $transport->connect($host, (int) $port, 1.0);
            $this->fail('Nothing is listening, so the connect must fail');
        } catch (ConnectionException $error) {
            $this->assertNotInstanceOf(TlsException::class, $error);
            $this->assertFalse($transport->isConnected());
        }
    }
}
