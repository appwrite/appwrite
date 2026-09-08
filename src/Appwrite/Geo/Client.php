<?php

namespace Appwrite\Geo;

use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleClientAdapter;
use Utopia\Client as HttpClient;
use Utopia\Client\Pool as HttpClientPool;
use Utopia\Console;
use Utopia\Pools\Adapter\Swoole as SwoolePoolAdapter;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    private readonly Factory $requests;

    public function __construct(private readonly ClientInterface $client)
    {
        $this->requests = new Factory();
    }

    public static function pooled(string $endpoint, string $secret, float $timeout = 3.0, int $poolSize = 64): self
    {
        $host = \parse_url($endpoint, PHP_URL_HOST) ?: $endpoint;

        return new self(new HttpClientPool(new Connections(
            new SwoolePoolAdapter(),
            "geo.{$host}",
            $poolSize,
            fn () => (new HttpClient((new SwooleClientAdapter())->withConnectionReuse()->withTimeout($timeout)->withConnectTimeout($timeout)))
                ->withBaseUri(\rtrim($endpoint, '/') . '/')
                ->withBearerAuth($secret),
            timeout: 3.0
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $ip): ?array
    {
        if ($ip === '') {
            return null;
        }

        try {
            // Relative path so the endpoint's base path (e.g. `/v1`) is kept.
            $response = $this->client->sendRequest($this->requests->createRequest(Method::GET, "ips/{$ip}"));
            if ($response->getStatusCode() === 200) {
                $record = \json_decode((string) $response->getBody(), true);
                if (\is_array($record) && $record !== []) {
                    return $record;
                }
            }
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
        }

        return null;
    }
}
