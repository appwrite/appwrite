<?php

namespace Tests\Telemetry\Adapter\OpenTelemetry\Swoole;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

use Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole;
use Utopia\Telemetry\Exception;

/**
 * Unit tests for the Swoole Transport implementation.
 *
 * These tests focus on the transport's internal behavior:
 * - Connection pooling
 * - Configuration
 * - Shutdown handling
 * - Error handling
 */
#[RequiresPhpExtension('swoole')]
class TransportTest extends TestCase
{
    private function runInCoroutine(callable $callback): mixed
    {
        $result = null;
        $exception = null;

        run(function () use ($callback, &$result, &$exception) {
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    public function testConstructorParsesEndpointCorrectly(): void
    {
        $transport = new Swoole('https://otel.example.com:4318/v1/metrics?foo=bar');

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testConstructorWithHttpEndpoint(): void
    {
        $transport = new Swoole('http://localhost:4318/v1/metrics');

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testConstructorWithCustomContentType(): void
    {
        $transport = new Swoole(
            'http://localhost:4318/v1/metrics',
            ContentTypes::JSON,
        );

        $this->assertEquals(ContentTypes::JSON, $transport->contentType());
    }

    public function testConstructorWithCustomHeaders(): void
    {
        $transport = new Swoole(
            'http://localhost:4318/v1/metrics',
            ContentTypes::PROTOBUF,
            ['Authorization' => 'Bearer token123'],
        );

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $transport = new Swoole(
            'http://localhost:4318/v1/metrics',
            ContentTypes::PROTOBUF,
            [],
            5.0,
        );

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testConstructorWithCustomPoolSize(): void
    {
        $transport = new Swoole(
            'http://localhost:4318/v1/metrics',
            ContentTypes::PROTOBUF,
            [],
            10.0,
            16,
        );

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testConstructorWithCustomSocketBufferSize(): void
    {
        $transport = new Swoole(
            'http://localhost:4318/v1/metrics',
            ContentTypes::PROTOBUF,
            [],
            10.0,
            8,
            128 * 1024,
        );

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testShutdownReturnsTrue(): void
    {
        $this->runInCoroutine(function () {
            $transport = new Swoole('http://localhost:4318/v1/metrics');

            $result = $transport->shutdown();

            $this->assertTrue($result);
        });
    }

    public function testForceFlushReturnsTrue(): void
    {
        $this->runInCoroutine(function () {
            $transport = new Swoole('http://localhost:4318/v1/metrics');

            $result = $transport->forceFlush();

            $this->assertTrue($result);
        });
    }

    public function testSendAfterShutdownReturnsError(): void
    {
        $this->runInCoroutine(function () {
            $transport = new Swoole('http://localhost:4318/v1/metrics');

            $transport->shutdown();

            $future = $transport->send('test payload');

            try {
                $future->await();
                $this->fail('Expected exception was not thrown');
            } catch (Exception $e) {
                $this->assertInstanceOf(Exception::class, $e);
                $this->assertEquals('Transport has been shut down', $e->getMessage());
            }
        });
    }

    public function testMultipleShutdownsAreSafe(): void
    {
        $this->runInCoroutine(function () {
            $transport = new Swoole('http://localhost:4318/v1/metrics');

            $result1 = $transport->shutdown();
            $result2 = $transport->shutdown();

            $this->assertTrue($result1);
            $this->assertTrue($result2);
        });
    }

    public function testEndpointWithQueryString(): void
    {
        $transport = new Swoole('http://localhost:4318/v1/metrics?api_key=secret&env=test');

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testMalformedUrlThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid endpoint URL');

        new Swoole('http:///v1/metrics');
    }

    public function testDefaultPortForHttp(): void
    {
        $transport = new Swoole('http://example.com/v1/metrics');

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testDefaultPortForHttps(): void
    {
        $transport = new Swoole('https://example.com/v1/metrics');

        $this->assertEquals(ContentTypes::PROTOBUF, $transport->contentType());
    }

    public function testContentTypeMatchesConstructor(): void
    {
        $jsonTransport = new Swoole('http://localhost:4318', ContentTypes::JSON);
        $protobufTransport = new Swoole('http://localhost:4318', ContentTypes::PROTOBUF);

        $this->assertEquals(ContentTypes::JSON, $jsonTransport->contentType());
        $this->assertEquals(ContentTypes::PROTOBUF, $protobufTransport->contentType());
    }
}
