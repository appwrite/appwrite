<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\Curl;

use Psr\Http\Message\RequestInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Adapter\Curl\Client;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Client\Adapter\AdapterContract;
use Utopia\Tests\Server\Http;

final class ClientTest extends AdapterContract
{
    public function testItStreamsLargeRequestBodiesWithBoundedMemory(): void
    {
        $size = 8 * 1024 * 1024;
        $path = $this->writeLargeTempFile($size);

        try {
            Http::serve(function (int $port) use ($path, $size): void {
                $expected = $size . ':' . hash_file('sha256', $path);
                $request = new Request\Factory()
                    ->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/body-info')
                    ->withBody(new Stream\Factory()->createStreamFromFile($path));

                $peak = $this->peakWhileSending($request);

                $this->assertSame($expected, $peak['body']);
                $this->assertLessThan(2 * 1_048_576, $peak['peak'], 'Uploading must not buffer the whole body.');
            });
        } finally {
            unlink($path);
        }
    }

    public function testItStreamsLargeMultipartUploadsWithBoundedMemory(): void
    {
        $size = 8 * 1024 * 1024;
        $path = $this->writeLargeTempFile($size);

        try {
            Http::serve(function (int $port) use ($path, $size): void {
                $request = new Request\Factory()->multipart(Method::POST, 'http://127.0.0.1:' . $port . '/multipart', [
                    'name' => 'Ada',
                    'file' => Part::file('file', $path, 'data.bin', ContentType::OCTET_STREAM),
                ]);

                $peak = $this->peakWhileSending($request);

                $this->assertSame('Ada:' . $size . ':' . hash_file('sha256', $path), $peak['body']);
                $this->assertLessThan(2 * 1_048_576, $peak['peak'], 'Multipart uploads must not buffer the whole file.');
            });
        } finally {
            unlink($path);
        }
    }

    /**
     * @return array{body: string, peak: int}
     */
    private function peakWhileSending(RequestInterface $request): array
    {
        $baseline = memory_get_usage();
        memory_reset_peak_usage();

        $response = $this->createAdapter()->sendRequest($request);

        return [
            'body' => (string) $response->getBody(),
            'peak' => memory_get_peak_usage() - $baseline,
        ];
    }

    /**
     * @param array<int, mixed> $transportOptions
     */
    protected function createAdapter(array $transportOptions = []): Adapter
    {
        return new Client(new Response\Factory(), new Stream\Factory(), $transportOptions);
    }

    protected function runAdapter(callable $callback): void
    {
        $callback();
    }

    protected function requireAdapterAvailable(): void
    {
        $this->assertTrue(\extension_loaded('curl'), 'The curl extension is required.');
    }

    /**
     * @return array<int, mixed>
     */
    protected function invalidTransportOptions(): array
    {
        return [
            999_999_999 => true,
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function timeoutOptions(float $timeout, ?float $connectTimeout = null): array
    {
        $options = [
            \CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
        ];

        if ($connectTimeout !== null) {
            $options[\CURLOPT_CONNECTTIMEOUT_MS] = (int) round($connectTimeout * 1000);
        }

        return $options;
    }

    /**
     * @return array<int, mixed>
     */
    protected function proxyOptions(int $port): array
    {
        return [
            \CURLOPT_PROXY => '127.0.0.1:' . $port,
            \CURLOPT_PROXYTYPE => \CURLPROXY_SOCKS5,
        ];
    }

    /**
     * @return array<int, bool>
     */
    protected function followRedirectsTransportOptions(bool $enabled): array
    {
        return [
            \CURLOPT_FOLLOWLOCATION => $enabled,
        ];
    }
}
