<?php

declare(strict_types=1);

namespace Tests\Unit\Antivirus;

use Appwrite\Antivirus\Client;
use Appwrite\Antivirus\Exception;
use Appwrite\Antivirus\Result;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends TestCase
{
    private ?RequestInterface $request = null;

    private function client(ResponseInterface $response): Client
    {
        $test = $this;

        return new Client(new class ($response, $test) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private ClientTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->setRequest($request);

                return $this->response;
            }
        });
    }

    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function testPingReady(): void
    {
        $this->assertTrue($this->client(new Response(200, body: new Stream('{"ready":true}')))->ping());
        $this->assertSame('GET', $this->request->getMethod());
        $this->assertSame('ready', (string) $this->request->getUri());
    }

    public function testPingNotReady(): void
    {
        $this->assertFalse($this->client(new Response(503, body: new Stream('{"ready":false}')))->ping());
    }

    public function testPingTransportFailure(): void
    {
        $client = new Client(new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        });

        $this->assertFalse($client->ping());
    }

    public function testVersion(): void
    {
        $response = new Response(200, body: new Stream(\json_encode([
            'databases' => [
                ['name' => 'main', 'version' => 64],
                ['name' => 'daily', 'version' => 27800],
            ],
        ])));

        $this->assertSame('main:64 daily:27800', $this->client($response)->version());
        $this->assertSame('GET', $this->request->getMethod());
        $this->assertSame('info', (string) $this->request->getUri());
    }

    public function testVersionWhenUnavailable(): void
    {
        $this->assertSame('', $this->client(new Response(503))->version());
    }

    public function testScanClean(): void
    {
        $response = new Response(200, body: new Stream(\json_encode([
            'result' => 'clean',
            'size' => 11,
            'md5' => 'abc',
            'sha1' => 'def',
            'sha256' => 'ghi',
            'duration_us' => 42,
        ])));

        $result = $this->client($response)->scan(new Stream('hello world'));

        $this->assertTrue($result->isClean());
        $this->assertFalse($result->isInfected());
        $this->assertSame(Result::CLEAN, $result->verdict);
        $this->assertNull($result->signature);
        $this->assertSame(11, $result->size);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('scan', (string) $this->request->getUri());
        $this->assertSame('application/octet-stream', $this->request->getHeaderLine('Content-Type'));
        $this->assertSame('hello world', (string) $this->request->getBody());
        $this->assertSame('11', $this->request->getHeaderLine('Content-Length'));
    }

    public function testScanInfected(): void
    {
        $response = new Response(200, body: new Stream(\json_encode([
            'result' => 'infected',
            'signature' => 'Eicar-Test-Signature',
            'size' => 68,
        ])));

        $result = $this->client($response)->scan(new Stream('X5O!P%@AP'));

        $this->assertTrue($result->isInfected());
        $this->assertSame('Eicar-Test-Signature', $result->signature);
    }

    public function testScanHashInfected(): void
    {
        $response = new Response(200, body: new Stream(\json_encode([
            'result' => 'infected',
            'signature' => 'Eicar-Test-Signature',
            'size' => 68,
        ])));

        $result = $this->client($response)->scanHash('44D88612FEA8A8F36DE82E1278ABB02F', 68);

        $this->assertTrue($result->isInfected());
        $this->assertSame('Eicar-Test-Signature', $result->signature);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('scan/hash', (string) $this->request->getUri());
        $this->assertSame('application/json', $this->request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'hash' => '44d88612fea8a8f36de82e1278abb02f',
            'size' => 68,
        ], \json_decode((string) $this->request->getBody(), true));
    }

    public function testScanHashOmitsSizeWhenNull(): void
    {
        $response = new Response(200, body: new Stream(\json_encode([
            'result' => 'clean',
            'size' => 0,
        ])));

        $this->client($response)->scanHash('44d88612fea8a8f36de82e1278abb02f');

        $this->assertSame(['hash' => '44d88612fea8a8f36de82e1278abb02f'], \json_decode((string) $this->request->getBody(), true));
    }

    public function testScanError(): void
    {
        $response = new Response(413, body: new Stream('{"error":"payload too large"}'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('payload too large');
        $this->expectExceptionCode(413);

        $this->client($response)->scan(new Stream('too-big'));
    }

    public function testScanUnknownVerdict(): void
    {
        $response = new Response(200, body: new Stream('{"result":"unknown"}'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Antivirus returned an unknown verdict');

        $this->client($response)->scan(new Stream('x'));
    }

    public function testScanTransportFailure(): void
    {
        $client = new Client(new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
            }
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Antivirus is not available: connection refused');

        $client->scan(new Stream('x'));
    }
}
