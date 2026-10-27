<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
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
     * @return \Iterator<string, array{string, bool, string}>
     */
    public static function discoveredServerSchemeProvider(): \Iterator
    {
        yield 'tls scheme' => ['tls://seed:4222', false, 'tls'];
        yield 'wss scheme' => ['wss://seed:443', false, 'tls'];
        yield 'tls option' => ['nats://seed:4222', true, 'tls'];
        yield 'plaintext' => ['nats://seed:4222', false, 'nats'];
    }

    #[DataProvider('discoveredServerSchemeProvider')]
    public function testDiscoveredServerInheritsRequiredTls(string $server, bool $tls, string $expected): void
    {
        /** @var list<string> $schemes */
        $schemes = [];
        /** @var list<FakeTransport> $transports */
        $transports = [];
        $factory = function (string $scheme) use (&$schemes, &$transports): FakeTransport {
            $schemes[] = $scheme;
            $transports[] = $transport = new FakeTransport(['connect_urls' => ['peer:4222']]);

            return $transport;
        };

        $conn = Connection::connect(new ConnectionOptions(
            servers: $server,
            tls: $tls,
            noRandomize: true,
            transportFactory: $factory,
        ));

        $transports[0]->pushInbound('INFO {"server_id":"FAKE","ldm":true}' . "\r\n");
        $conn->processMessage(1.0);

        $this->assertCount(2, $transports);
        $this->assertSame('peer', $transports[1]->host);
        $this->assertSame($expected, $schemes[1]);

        $conn->close();
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
