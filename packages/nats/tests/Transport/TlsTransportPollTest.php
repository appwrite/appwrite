<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Transport;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Tests\Support\Peer;
use Utopia\NATS\Tests\Support\Wait;
use Utopia\NATS\Transport\TlsTransport;

#[RequiresPhpExtension('openssl')]
final class TlsTransportPollTest extends TestCase
{
    private const string CERTIFICATES = __DIR__ . '/../fixtures/certs';

    private ?Peer $peer = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->peer?->stop();
    }

    public function testDataIsReadableAndLeftForTheRead(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send("PING\r\n");

        $this->assertTrue(Wait::until($transport->poll(...)));
        $this->assertSame("PING\r\n", $transport->readLine(1.0));

        $transport->close();
    }

    public function testIdleConnectionIsNotReadableOnceTheDataIsRead(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send("INFO {}\r\n");
        $this->assertSame("INFO {}\r\n", $transport->readLine(1.0));

        $this->assertFalse($transport->poll());

        $transport->close();
    }

    public function testDecryptedDataLeftInTheStreamBufferIsReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send("PING\r\nPONG\r\n");

        $this->assertSame("PING\r\n", $transport->readLine(1.0));
        $this->assertTrue($transport->poll(), 'The rest of the record sits in the stream buffer, not the socket');

        $transport->close();
    }

    public function testClosedByThePeerIsReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send("INFO {}\r\n");
        $transport->readLine(1.0);
        $peer->close();

        $this->assertTrue(Wait::until($transport->poll(...)));

        $transport->close();
    }

    #[RequiresPhpExtension('sockets')]
    public function testResetByThePeerIsReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send("INFO {}\r\n");
        $transport->readLine(1.0);
        $peer->reset();

        $this->assertTrue(Wait::until($transport->poll(...)), 'A reset must read as ready, so the next read reports it');

        $transport->close();
    }

    public function testClosedTransportIsReadable(): void
    {
        [$transport] = $this->connect();
        $transport->close();

        $this->assertTrue($transport->poll(), 'A read would raise at once');
    }

    /** @return array{TlsTransport, Peer} */
    private function connect(): array
    {
        $this->peer = new Peer(certificates: self::CERTIFICATES);
        $transport = new TlsTransport([
            'cafile' => self::CERTIFICATES . '/ca.pem',
            'peer_name' => 'localhost',
        ]);
        $transport->connect('127.0.0.1', $this->peer->port, 5.0);
        $this->peer->accept();

        return [$transport, $this->peer];
    }
}
