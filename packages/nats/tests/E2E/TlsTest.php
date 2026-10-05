<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;

/**
 * End-to-end TLS / mTLS tests against a server that requires client certificates
 * (tests/fixtures/nats-tls.conf, verify: true). Certificates live in
 * tests/fixtures/certs and are generated with a 100-year validity.
 */
final class TlsTest extends TestCase
{
    private string $url;
    private string $certs;

    protected function setUp(): void
    {
        $this->url = getenv('NATS_TLS_URL') ?: 'tls://127.0.0.1:14223';
        $this->certs = __DIR__ . '/../fixtures/certs';

        // Skip when the TLS server isn't running (e.g. local runs without the
        // nats-tls compose service); CI brings it up so the tests execute there.
        $host = parse_url($this->url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($this->url, PHP_URL_PORT) ?: 14223;
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("TLS server not reachable at {$this->url}");
        }
        fclose($probe);
    }

    public function testMutualTlsConnectAndRoundTrip(): void
    {
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->url,
            tlsCaFile: "{$this->certs}/ca.pem",
            tlsCertFile: "{$this->certs}/client-cert.pem",
            tlsKeyFile: "{$this->certs}/client-key.pem",
        ));

        $this->assertTrue($conn->isConnected());

        $sub = $conn->subscribe('tls.echo');
        $conn->publish('tls.echo', 'secure-hello');
        $msg = $sub->nextMessage(2.0);

        $this->assertInstanceOf(\Utopia\NATS\Message::class, $msg);
        $this->assertSame('secure-hello', $msg->data);

        $conn->close();
    }

    public function testConnectWithoutClientCertIsRejected(): void
    {
        // Server requires a client certificate (verify: true); connecting with only
        // the CA must fail the TLS handshake.
        $this->expectException(ConnectionException::class);

        Connection::connect(new ConnectionOptions(
            servers: $this->url,
            tlsCaFile: "{$this->certs}/ca.pem",
        ));
    }
}
