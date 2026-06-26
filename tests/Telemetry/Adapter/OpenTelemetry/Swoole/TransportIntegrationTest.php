<?php

declare(strict_types=1);

namespace Tests\Telemetry\Adapter\OpenTelemetry\Swoole;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

use Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole;
use Utopia\Telemetry\Exception;

/**
 * Integration tests for the Swoole Transport.
 *
 * These tests spin up a mock OTLP server and verify the transport
 * actually sends data correctly over HTTP.
 */
#[RequiresPhpExtension('swoole')]
final class TransportIntegrationTest extends TestCase
{
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
