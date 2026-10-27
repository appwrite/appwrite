<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Tests\Support\FakeTransport;
use Utopia\NATS\Transport\TlsTransport;

/**
 * Option wiring for TLS verification/SNI and for the dynamic token/JWT providers
 * that are resolved at (re)connect time.
 */
final class ConnectionTlsAndAuthTest extends TestCase
{
    private function connect(FakeTransport $fake, array $extra = []): Connection
    {
        $args = array_merge([
            'servers' => 'nats://127.0.0.1:4222',
            'transportFactory' => fn (string $scheme): FakeTransport => $fake,
        ], $extra);

        return Connection::connect(new ConnectionOptions(...$args));
    }

    public function testTlsOptionDefaults(): void
    {
        $options = new ConnectionOptions();
        $this->assertTrue($options->tlsVerify);
        $this->assertNull($options->tlsServerName);
    }

    public function testConnectionTlsOptionsMapping(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'tlsVerify' => false,
            'tlsServerName' => 'example.com',
        ]);

        $method = new \ReflectionMethod(Connection::class, 'tlsOptions');
        /** @var array<string, mixed> $tls */
        $tls = $method->invoke($conn);

        $this->assertFalse($tls['verify_peer']);
        $this->assertFalse($tls['verify_peer_name']);
        $this->assertSame('example.com', $tls['peer_name']);

        $conn->close();
    }

    public function testTlsTransportAppliesVerifyAndSni(): void
    {
        $transport = new TlsTransport([
            'verify_peer' => false,
            'verify_peer_name' => false,
            'peer_name' => 'example.com',
        ]);

        $method = new \ReflectionMethod(TlsTransport::class, 'buildSslOptions');
        /** @var array<string, mixed> $ssl */
        $ssl = $method->invoke($transport);

        $this->assertFalse($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
        $this->assertSame('example.com', $ssl['peer_name']);
    }

    /**
     * @return \Iterator<string, array{list<string>, bool, string}>
     */
    public static function discoveredServerSchemeProvider(): \Iterator
    {
        yield 'tls seed' => [['tls://seed:4222'], false, 'tls'];
        yield 'wss seed' => [['wss://seed:443'], false, 'tls'];
        yield 'tls option' => [['nats://seed:4222'], true, 'tls'];
        yield 'plaintext seed' => [['nats://seed:4222'], false, 'nats'];
        yield 'tls seed in a mixed pool' => [['tls://seed:4222', 'nats://other:4222'], false, 'tls'];
        yield 'plaintext seed in a mixed pool' => [['nats://seed:4222', 'tls://other:4222'], false, 'nats'];
    }

    /**
     * @param list<string> $servers
     */
    #[DataProvider('discoveredServerSchemeProvider')]
    public function testDiscoveredServerInheritsTheAdvertisingServersTls(array $servers, bool $tls, string $expected): void
    {
        /** @var list<string> $schemes */
        $schemes = [];
        /** @var list<FakeTransport> $transports */
        $transports = [];
        $factory = function (string $scheme) use (&$schemes, &$transports): FakeTransport {
            $schemes[] = $scheme;
            $transports[] = $transport = new FakeTransport(['connect_urls' => ['peer:4222']]);
            $transport->refuseHosts = ['other'];

            return $transport;
        };

        $conn = Connection::connect(new ConnectionOptions(
            servers: $servers,
            tls: $tls,
            noRandomize: true,
            transportFactory: $factory,
        ));

        $this->assertSame('seed', $transports[0]->host);
        $transports[0]->pushInbound('INFO {"server_id":"FAKE","ldm":true}' . "\r\n");
        $conn->processMessage(1.0);

        $peer = end($transports);
        $this->assertInstanceOf(FakeTransport::class, $peer);
        $this->assertSame('peer', $peer->host);
        $this->assertSame($expected, end($schemes));

        $conn->close();
    }

    public function testExplicitPlaintextSeedStaysPlaintextBesideATlsSeed(): void
    {
        try {
            Connection::connect(new ConnectionOptions(
                servers: ['tls://127.0.0.1:1', 'nats://127.0.0.1:1'],
                noRandomize: true,
                connectTimeout: 0.5,
                connectAttempts: 1,
            ));
            $this->fail('Nothing listens on port 1');
        } catch (ConnectionException $error) {
            $previous = $error->getPrevious();
            $this->assertInstanceOf(ConnectionException::class, $previous);
            $this->assertStringContainsString('tcp://127.0.0.1:1', $previous->getMessage(), 'the nats:// seed is dialled without TLS');
        }
    }

    public function testTokenProviderResolvedAtConnect(): void
    {
        $calls = 0;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'tokenProvider' => function () use (&$calls): string {
                $calls++;
                return 'token-' . $calls;
            },
        ]);

        $this->assertSame(1, $calls, 'token provider invoked once at connect');
        $this->assertSame('token-1', $fake->connectPayload()['auth_token']);

        $conn->close();
    }

    public function testJwtProviderResolvedAtConnect(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'jwtProvider' => fn (): string => 'my.jwt.token',
        ]);

        $this->assertSame('my.jwt.token', $fake->connectPayload()['jwt']);

        $conn->close();
    }
}
