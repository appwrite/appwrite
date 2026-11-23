<?php

declare(strict_types=1);

namespace Utopia\Telemetry\Tests\E2E\Adapter\OpenTelemetry\Swoole;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Server as TcpServer;
use Swoole\Coroutine\Server\Connection;
use Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole;
use Utopia\Telemetry\Exception;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

/**
 * Integration tests for the Swoole Transport.
 *
 * These tests spin up a mock OTLP server and verify the transport
 * actually sends data correctly over HTTP.
 */
#[RequiresPhpExtension('swoole')]
final class TransportIntegrationTest extends TestCase
{
    private ?string $certificates = null;

    /** @var array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, path: string}|null */
    private ?array $authority = null;

    protected function tearDown(): void
    {
        if ($this->certificates !== null) {
            array_map(unlink(...), glob($this->certificates . '/*') ?: []);
            rmdir($this->certificates);
            $this->certificates = null;
        }
        $this->authority = null;
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesCollectorWithUntrustedCertificate(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('DNS:localhost');

        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: 'https://localhost:' . $server->getPort() . '/v1/metrics',
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
            );

            $this->assertRefused($transport);
            $this->assertSame(0, $server->getRequestCount());
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesCollectorWhoseCertificateNamesAnotherHost(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('DNS:collector.internal');

        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: 'https://localhost:' . $server->getPort() . '/v1/metrics',
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
                certificateAuthority: $this->authority()['path'],
            );

            $this->assertRefused($transport);
            $this->assertSame(0, $server->getRequestCount());
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testSendsToCollectorWithTrustedCertificate(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('DNS:localhost');

        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $server->respondWith(200, 'OK');

            $transport = new Swoole(
                endpoint: 'https://localhost:' . $server->getPort() . '/v1/metrics',
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
                certificateAuthority: $this->authority()['path'],
            );

            try {
                $this->assertSame('OK', $transport->send('payload')->await());
            } finally {
                $transport->shutdown();
            }

            $request = $server->getLastRequest();
            $this->assertNotNull($request);
            $this->assertSame('payload', $request['payload']);
            $this->assertSame('Bearer collector-token', $request['headers']['authorization']);
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testSendsToCollectorWhoseCertificateNamesItsAddress(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('IP:127.0.0.1');

        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $server->respondWith(200, 'OK');

            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
                certificateAuthority: $this->authority()['path'],
            );

            try {
                $this->assertSame('OK', $transport->send('payload')->await());
            } finally {
                $transport->shutdown();
            }

            $request = $server->getLastRequest();
            $this->assertNotNull($request);
            $this->assertSame('payload', $request['payload']);
            $this->assertSame('Bearer collector-token', $request['headers']['authorization']);
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesCollectorWhoseCertificateNamesAnotherAddress(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('IP:127.0.0.2');

        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint() . '?tenant=collector-tenant',
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
                certificateAuthority: $this->authority()['path'],
            );

            $this->assertRefused($transport);
            $this->assertSame([], $server->getRequests(), 'The endpoint path and query must not reach an unverified collector.');
            $this->assertContains('HEAD /', $server->getStrays(), 'The address check must reach the collector to read its certificate.');
            $this->assertNothingExported($server);
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesToReconnectToCollectorWhoseCertificateNamesAnotherAddress(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('IP:127.0.0.1');
        $impostor = $this->issueCertificate('IP:127.0.0.2');

        MockOtlpServer::run(function (MockOtlpServer $server) use ($impostor): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                headers: ['Authorization' => 'Bearer collector-token'],
                timeout: 1.0,
                poolSize: 1,
                certificateAuthority: $this->authority()['path'],
            );

            try {
                $transport->send('first')->await();

                $server->restart($impostor['certificate'], $impostor['key']);
                $server->reset();

                $this->assertRefused($transport);
            } finally {
                $transport->shutdown();
            }

            $this->assertNothingExported($server);
        }, $certificate, $key);
    }

    #[RequiresPhpExtension('openssl')]
    public function testReconnectsToCollectorAddressedByIpAfterItDropsThePooledConnection(): void
    {
        ['certificate' => $certificate, 'key' => $key] = $this->issueCertificate('IP:127.0.0.1');

        MockOtlpServer::run(function (MockOtlpServer $server) use ($certificate, $key): void {
            $server->respondWith(200, 'OK');

            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                timeout: 1.0,
                poolSize: 1,
                certificateAuthority: $this->authority()['path'],
            );

            try {
                $this->assertSame('OK', $transport->send('first')->await());

                $server->restart($certificate, $key);

                $this->assertSame('OK', $transport->send('second')->await());
            } finally {
                $transport->shutdown();
            }

            $request = $server->getLastRequest();
            $this->assertNotNull($request);
            $this->assertSame('second', $request['payload']);
        }, $certificate, $key);
    }

    private function assertRefused(Swoole $transport): void
    {
        try {
            $transport->send('payload')->await();
            $this->fail('An export must not be sent to a collector whose certificate is not trusted for the endpoint.');
        } catch (Exception $e) {
            $this->assertStringContainsString('OTLP connection failed', $e->getMessage());
        } finally {
            $transport->shutdown();
        }
    }

    private function assertNothingExported(MockOtlpServer $server): void
    {
        foreach ($server->getStrays() as $request) {
            $this->assertStringEndsWith(' /', $request, 'No endpoint path or query may reach an unverified collector.');
        }

        foreach ($server->getRequests() as $request) {
            $this->assertSame('', $request['payload'], 'No export payload may reach an unverified collector.');
            $this->assertArrayNotHasKey('authorization', $request['headers'], 'No credentials may reach an unverified collector.');
        }
    }

    /**
     * Issue a certificate for the given subject alternative names, signed by the test authority.
     *
     * @return array{certificate: string, key: string}
     */
    private function issueCertificate(string $names): array
    {
        $authority = $this->authority();
        $key = $this->generateKey();
        $certificate = $this->sign($key, 'collector', <<<EXTENSIONS
            basicConstraints = critical, CA:FALSE
            keyUsage = critical, digitalSignature
            extendedKeyUsage = serverAuth
            subjectAltName = {$names}
            EXTENSIONS, $authority);

        $paths = [
            'certificate' => $this->directory() . '/' . bin2hex(random_bytes(8)) . '.pem',
            'key' => $this->directory() . '/' . bin2hex(random_bytes(8)) . '.key',
        ];
        $this->assertTrue(openssl_x509_export_to_file($certificate, $paths['certificate']));
        $this->assertTrue(openssl_pkey_export_to_file($key, $paths['key'], null, ['config' => $this->configuration('')]));

        return $paths;
    }

    /**
     * The authority signing every issued certificate, which a transport trusts through its PEM file at `path`.
     *
     * @return array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, path: string}
     */
    private function authority(): array
    {
        if ($this->authority === null) {
            $key = $this->generateKey();
            $certificate = $this->sign($key, 'Telemetry Test Authority', <<<'EXTENSIONS'
                basicConstraints = critical, CA:TRUE
                keyUsage = critical, keyCertSign
                EXTENSIONS);

            $path = $this->directory() . '/authority.pem';
            $this->assertTrue(openssl_x509_export_to_file($certificate, $path));

            $this->authority = ['certificate' => $certificate, 'key' => $key, 'path' => $path];
        }

        return $this->authority;
    }

    /**
     * Sign a certificate for the key, by the issuer or by the key itself when there is none.
     *
     * @param array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, path: string}|null $issuer
     */
    private function sign(\OpenSSLAsymmetricKey $key, string $commonName, string $extensions, ?array $issuer = null): \OpenSSLCertificate
    {
        $options = [
            'config' => $this->configuration($extensions),
            'x509_extensions' => 'extensions',
            'digest_alg' => 'sha256',
        ];

        $signer = $issuer === null ? $key : $issuer['key'];

        $request = openssl_csr_new(['commonName' => $commonName], $key, $options);
        $this->assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);

        $certificate = openssl_csr_sign(
            $request,
            $issuer === null ? null : $issuer['certificate'],
            $signer,
            1,
            $options,
            random_int(1, PHP_INT_MAX),
        );
        $this->assertInstanceOf(\OpenSSLCertificate::class, $certificate);

        return $certificate;
    }

    private function generateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'config' => $this->configuration(''),
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    /**
     * Write an OpenSSL configuration with the given x509 extensions, so no system configuration is needed.
     */
    private function configuration(string $extensions): string
    {
        $path = $this->directory() . '/' . bin2hex(random_bytes(8)) . '.cnf';
        file_put_contents($path, "[req]\ndistinguished_name = subject\n\n[subject]\n\n[extensions]\n{$extensions}\n");

        return $path;
    }

    private function directory(): string
    {
        if ($this->certificates === null) {
            $this->certificates = sys_get_temp_dir() . '/telemetry-tls-' . bin2hex(random_bytes(8));
            mkdir($this->certificates);
        }

        return $this->certificates;
    }

    public function testSendPayloadToServer(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $server->respondWith(200, 'OK');

            $transport = new Swoole($server->getEndpoint());
            $testPayload = 'test-metric-payload-data';

            $result = $transport->send($testPayload)->await();

            $this->assertEquals('OK', $result);

            $request = $server->getLastRequest();
            $this->assertSame($testPayload, $request['payload']);
            $this->assertEquals(ContentTypes::PROTOBUF, $request['headers']['content-type']);
            $this->assertEquals((string) \strlen($testPayload), $request['headers']['content-length']);

            $transport->shutdown();
        });
    }

    public function testRetriesOnceWhenThePooledConnectionIsResetMidRequest(): void
    {
        run(function (): void {
            $received = [];
            $server = new TcpServer('127.0.0.1', 19418);
            $server->handle(function (Connection $connection) use (&$received): void {
                $socket = $connection->exportSocket();
                $buffer = '';

                while (\is_string($chunk = $socket->recv(65536, 1.0)) && $chunk !== '') {
                    $buffer .= $chunk;

                    while (($request = $this->takeRequest($buffer)) !== null) {
                        $received[] = $request;

                        if (\count($received) === 2) {
                            $socket->setOption(SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                            $socket->close();

                            return;
                        }

                        $socket->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                    }
                }
            });
            go(fn (): bool => $server->start());
            Coroutine::sleep(0.05);

            $transport = new Swoole('http://127.0.0.1:19418/v1/metrics');

            try {
                $this->assertSame('OK', $transport->send('first')->await());
                $this->assertSame('OK', $transport->send('second')->await());
            } finally {
                $transport->shutdown();
                $server->shutdown();
            }

            $this->assertCount(3, $received);
            $this->assertStringEndsWith('first', $received[0]);
            $this->assertStringEndsWith('second', $received[1]);
            $this->assertStringEndsWith('second', $received[2]);
        });
    }

    public function testDoesNotRetryWhenTheCollectorIsTooSlowToAnswer(): void
    {
        run(function (): void {
            $received = [];
            $server = new TcpServer('127.0.0.1', 19419);
            $server->handle(function (Connection $connection) use (&$received): void {
                $socket = $connection->exportSocket();
                $buffer = '';

                while (\is_string($chunk = $socket->recv(65536, 2.0)) && $chunk !== '') {
                    $buffer .= $chunk;

                    while (($request = $this->takeRequest($buffer)) !== null) {
                        $received[] = $request;

                        if (\count($received) === 2) {
                            Coroutine::sleep(1.0);

                            return;
                        }

                        $socket->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                    }
                }
            });
            go(fn (): bool => $server->start());
            Coroutine::sleep(0.05);

            $transport = new Swoole('http://127.0.0.1:19419/v1/metrics', timeout: 0.3);
            $failure = null;

            try {
                $this->assertSame('OK', $transport->send('first')->await());

                try {
                    $transport->send('second')->await();
                } catch (Exception $e) {
                    $failure = $e;
                }
            } finally {
                $transport->shutdown();
                $server->shutdown();
            }

            $this->assertInstanceOf(Exception::class, $failure);
            $this->assertCount(
                2,
                $received,
                'A timed-out export must not be sent again: the collector may have accepted it and still be working.',
            );
        });
    }

    /**
     * Take one complete HTTP request off the front of the buffer, or null while
     * one is still arriving. TCP splits and coalesces writes freely, so a read
     * is not a request.
     */
    private function takeRequest(string &$buffer): ?string
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $headers = substr($buffer, 0, $headerEnd);
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $matches) !== 1) {
            return null;
        }

        $end = $headerEnd + 4 + (int) $matches[1];
        if (\strlen($buffer) < $end) {
            return null;
        }

        $request = substr($buffer, 0, $end);
        $buffer = substr($buffer, $end);

        return $request;
    }

    public function testSendWithCustomHeaders(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                headers: [
                    'Authorization' => 'Bearer test-token',
                    'X-Custom-Header' => 'custom-value',
                ],
            );

            $transport->send('payload')->await();

            $request = $server->getLastRequest();
            $this->assertEquals('Bearer test-token', $request['headers']['authorization']);
            $this->assertEquals('custom-value', $request['headers']['x-custom-header']);

            $transport->shutdown();
        });
    }

    public function testSendHandlesServerError(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $server->respondWith(500, 'Internal Server Error');

            $transport = new Swoole($server->getEndpoint());

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('500');

            try {
                $transport->send('payload')->await();
            } finally {
                $transport->shutdown();
            }
        });
    }

    public function testMultipleSequentialSends(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                poolSize: 2,
            );

            for ($i = 0; $i < 10; $i++) {
                $transport->send("payload-$i")->await();
            }

            $this->assertSame(10, $server->getRequestCount());

            $transport->shutdown();
        });
    }

    public function testConcurrentSends(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $server->withDelay(0.01);

            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                poolSize: 4,
            );

            $wg = new \Swoole\Coroutine\WaitGroup();
            $concurrentRequests = 20;

            for ($i = 0; $i < $concurrentRequests; $i++) {
                $wg->add();
                go(function () use ($transport, $i, $wg): void {
                    $transport->send("concurrent-payload-$i")->await();
                    $wg->done();
                });
            }

            $wg->wait();

            $this->assertSame($concurrentRequests, $server->getRequestCount());

            $transport->shutdown();
        });
    }

    public function testJsonContentType(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                contentType: ContentTypes::JSON,
            );

            $transport->send('{"metrics":[]}')->await();

            $request = $server->getLastRequest();
            $this->assertEquals(ContentTypes::JSON, $request['headers']['content-type']);

            $transport->shutdown();
        });
    }

    public function testConnectionTimeout(): void
    {
        $exception = null;

        run(function () use (&$exception): void {
            $transport = new Swoole(
                endpoint: 'http://127.0.0.1:19999/v1/metrics',
                timeout: 0.5,
            );

            $startTime = microtime(true);

            try {
                $transport->send('payload')->await();
            } catch (Exception $e) {
                $exception = $e;
            } finally {
                $elapsed = microtime(true) - $startTime;
                $this->assertLessThan(2.0, $elapsed);
                $transport->shutdown();
            }
        });

        $this->assertInstanceOf(\Utopia\Telemetry\Exception::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testKeepAliveConnectionReuse(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole(
                endpoint: $server->getEndpoint(),
                poolSize: 1,
            );

            // Send 5 requests with pool size 1
            for ($i = 0; $i < 5; $i++) {
                $transport->send("payload-$i")->await();
            }

            $this->assertSame(5, $server->getRequestCount());

            $transport->shutdown();
        });
    }

    public function testLargePayload(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole($server->getEndpoint());

            // 1MB payload
            $largePayload = str_repeat('x', 1024 * 1024);

            $transport->send($largePayload)->await();

            $request = $server->getLastRequest();
            $this->assertSame(\strlen($largePayload), \strlen($request['payload']));

            $transport->shutdown();
        });
    }

    public function testServerResetsRequestTracking(): void
    {
        MockOtlpServer::run(function (MockOtlpServer $server): void {
            $transport = new Swoole($server->getEndpoint());

            $transport->send('first')->await();
            $this->assertSame(1, $server->getRequestCount());

            $server->reset();
            $this->assertSame(0, $server->getRequestCount());

            $transport->send('second')->await();
            $this->assertSame(1, $server->getRequestCount());

            $transport->shutdown();
        });
    }
}
