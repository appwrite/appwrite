<?php

declare(strict_types=1);

namespace Tests\Unit\Antivirus;

use Appwrite\Antivirus\Client;
use Appwrite\Antivirus\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;

final class ScannerTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param list<ResponseInterface> $responses
     */
    private function scanner(array $responses, int $contentLimit = 20): Scanner
    {
        $test = $this;
        $http = new class ($responses, $test) implements ClientInterface {
            /**
             * @param list<ResponseInterface> $responses
             */
            public function __construct(private array $responses, private ScannerTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->addRequest($request);

                if ($this->responses === []) {
                    throw new \RuntimeException('Unexpected antivirus request');
                }

                return \array_shift($this->responses);
            }
        };

        return new Scanner(new Client($http), $contentLimit);
    }

    public function addRequest(RequestInterface $request): void
    {
        $this->requests[] = $request;
    }

    public function testHashInfectedSkipsContentScan(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->never())->method('read');

        $result = $this->scanner([
            new Response(200, body: new Stream(\json_encode([
                'result' => 'infected',
                'signature' => 'Eicar-Test-Signature',
            ]))),
        ])->scan($device, '/files/eicar', 68, '44d88612fea8a8f36de82e1278abb02f');

        $this->assertTrue($result->isInfected());
        $this->assertCount(1, $this->requests);
        $this->assertSame('scan/hash', (string) $this->requests[0]->getUri());
    }

    public function testHashCleanContentScansWhenUnderLimit(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->once())->method('read')->with('/files/clean')->willReturn(new Stream('hello'));

        $result = $this->scanner([
            new Response(200, body: new Stream(\json_encode(['result' => 'clean']))),
            new Response(200, body: new Stream(\json_encode(['result' => 'clean', 'size' => 5]))),
        ])->scan($device, '/files/clean', 5, '5d41402abc4b2a76b9719d911017c592');

        $this->assertTrue($result->isClean());
        $this->assertCount(2, $this->requests);
        $this->assertSame('scan/hash', (string) $this->requests[0]->getUri());
        $this->assertSame('scan', (string) $this->requests[1]->getUri());
        $this->assertSame('hello', (string) $this->requests[1]->getBody());
    }

    public function testHashCleanSkipsContentScanWhenOverLimit(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->never())->method('read');

        $result = $this->scanner([
            new Response(200, body: new Stream(\json_encode(['result' => 'clean']))),
        ], contentLimit: 20)->scan(
            $device,
            '/files/large',
            21,
            '5d41402abc4b2a76b9719d911017c592'
        );

        $this->assertTrue($result->isClean());
        $this->assertCount(1, $this->requests);
        $this->assertSame('scan/hash', (string) $this->requests[0]->getUri());
    }

    public function testHashCleanContentScanDetectsInfection(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->once())->method('read')->with('/files/body')->willReturn(new Stream('ndb-hit'));

        $result = $this->scanner([
            new Response(200, body: new Stream(\json_encode(['result' => 'clean']))),
            new Response(200, body: new Stream(\json_encode([
                'result' => 'infected',
                'signature' => 'Body.Signature',
            ]))),
        ])->scan($device, '/files/body', 7, '5d41402abc4b2a76b9719d911017c592');

        $this->assertTrue($result->isInfected());
        $this->assertSame('Body.Signature', $result->signature);
        $this->assertCount(2, $this->requests);
        $this->assertSame('scan', (string) $this->requests[1]->getUri());
    }

    public function testMultipartEtagContentScansWhenUnderLimit(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->once())->method('read')->with('/files/part')->willReturn(new Stream('chunk'));

        $result = $this->scanner([
            new Response(200, body: new Stream(\json_encode(['result' => 'clean']))),
        ])->scan($device, '/files/part', 5, 'd41d8cd98f00b204e9800998ecf8427e-2');

        $this->assertTrue($result->isClean());
        $this->assertCount(1, $this->requests);
        $this->assertSame('scan', (string) $this->requests[0]->getUri());
    }

    public function testMultipartEtagSkipsScanWhenOverLimit(): void
    {
        $device = $this->createMock(Device::class);
        $device->expects($this->never())->method('read');

        $result = $this->scanner([], contentLimit: 20)->scan(
            $device,
            '/files/huge',
            21,
            'd41d8cd98f00b204e9800998ecf8427e-2'
        );

        $this->assertTrue($result->isClean());
        $this->assertSame([], $this->requests);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function digestProvider(): array
    {
        return [
            'md5' => ['44d88612fea8a8f36de82e1278abb02f', true],
            'sha1' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709', true],
            'sha256' => ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', true],
            'uppercase md5' => ['44D88612FEA8A8F36DE82E1278ABB02F', true],
            's3 multipart etag' => ['d41d8cd98f00b204e9800998ecf8427e-2', false],
            'empty' => ['', false],
            'short' => ['abc', false],
        ];
    }

    #[DataProvider('digestProvider')]
    public function testIsDigest(string $hash, bool $expected): void
    {
        $this->assertSame($expected, Scanner::isDigest($hash));
    }
}
