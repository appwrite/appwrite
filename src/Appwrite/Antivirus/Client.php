<?php

namespace Appwrite\Antivirus;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;
use Utopia\Psr7\Stream;

class Client
{
    private Factory $factory;

    /**
     * @param ClientInterface $client Configured with the Defender base URI.
     */
    public function __construct(private readonly ClientInterface $client)
    {
        $this->factory = new Factory();
    }

    public function ping(): bool
    {
        try {
            return $this->client->sendRequest($this->factory->query(Method::GET, 'ready'))->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        try {
            $response = $this->client->sendRequest($this->factory->query(Method::GET, 'info'));
            if ($response->getStatusCode() !== 200) {
                return '';
            }

            $payload = $this->json($response);
            $databases = $payload['databases'] ?? [];
            if (!\is_array($databases) || $databases === []) {
                return '';
            }

            $parts = [];
            foreach ($databases as $database) {
                if (!\is_array($database)) {
                    continue;
                }

                $name = $database['name'] ?? '';
                $version = $database['version'] ?? '';
                if ($name === '' && $version === '') {
                    continue;
                }

                $parts[] = $name === '' ? (string) $version : $name . ':' . $version;
            }

            return \implode(' ', $parts);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Stream a local file to Defender as raw bytes so hashes and body signatures
     * are evaluated in one request without loading the file into PHP.
     *
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function scanPath(string $path): Result
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new Exception('Unable to open file for antivirus scan');
        }

        $handle = \fopen($path, 'rb');
        if ($handle === false) {
            throw new Exception('Unable to open file for antivirus scan');
        }

        return $this->scan(Stream::fromResource($handle));
    }

    /**
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function scan(StreamInterface $body): Result
    {
        $request = $this->factory
            ->body(Method::POST, 'scan', '', 'application/octet-stream')
            ->withBody($body);

        $size = $body->getSize();
        if ($size !== null) {
            $request = $request->withHeader('Content-Length', (string) $size);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new Exception('Antivirus is not available: ' . $e->getMessage(), 0, $e);
        }

        return $this->result($response);
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function json(ResponseInterface $response): array
    {
        try {
            $payload = \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Exception('Antivirus returned an invalid response', 0, $e);
        }

        if (!\is_array($payload)) {
            throw new Exception('Antivirus returned an invalid response');
        }

        return $payload;
    }

    /**
     * @throws Exception
     */
    private function result(ResponseInterface $response): Result
    {
        $status = $response->getStatusCode();
        $payload = $this->json($response);

        if ($status >= 400) {
            $error = $payload['error'] ?? null;
            throw new Exception(\is_string($error) ? $error : 'Antivirus scan failed with status ' . $status, $status);
        }

        $verdict = $payload['result'] ?? '';
        if ($verdict !== Result::CLEAN && $verdict !== Result::INFECTED) {
            throw new Exception('Antivirus returned an unknown verdict');
        }

        $signature = $payload['signature'] ?? null;

        return new Result(
            verdict: $verdict,
            signature: \is_string($signature) ? $signature : null,
            size: (int) ($payload['size'] ?? 0),
            md5: (string) ($payload['md5'] ?? ''),
            sha1: (string) ($payload['sha1'] ?? ''),
            sha256: (string) ($payload['sha256'] ?? ''),
            durationUs: (int) ($payload['duration_us'] ?? 0),
        );
    }
}
