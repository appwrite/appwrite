<?php

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Cloudflare implements Adapter
{
    /**
     * URLs per purge request. Names the batch size this adapter has always used rather than
     * changing it: Cloudflare documents 100 URLs per request, 500 on Enterprise, so 30 is within
     * every plan and can be raised deliberately.
     */
    public const int PATHS_PER_PURGE = 30;

    /**
     * Cache tags per purge request. Cloudflare documents 100 operations per request for tags on
     * every plan; 30 is what this adapter has always sent.
     */
    public const int KEYS_PER_PURGE = 30;

    private readonly ClientInterface $client;

    public function __construct(
        private readonly string $zoneId,
        private readonly string $apiToken,
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.cloudflare.com/client/v4',
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        foreach (array_chunk($paths, self::PATHS_PER_PURGE) as $chunk) {
            $urls = array_map(static fn(string $path): string => 'https://' . $domain . $path, $chunk);
            $this->send(['files' => $urls]);
        }
    }

    /**
     * Purges every cached response served for the hostname, and nothing served for another.
     */
    public function purgeDomain(string $domain): void
    {
        $this->send(['hosts' => [Domain::validate($domain)]]);
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        // Cache tags only match responses the origin tagged with a Cache-Tag header.
        foreach (array_chunk($keys, self::KEYS_PER_PURGE) as $chunk) {
            $this->send(['tags' => $chunk]);
        }
    }

    /**
     * Purges every cached response in the zone, whatever hostname it was served for.
     */
    public function purgeZone(): void
    {
        $this->send(['purge_everything' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(array $body): void
    {
        $result = $this->request(Method::POST, '/zones/' . $this->zoneId . '/purge_cache', $body);

        if (!$this->isSuccess($result)) {
            throw new \RuntimeException($this->formatError($result));
        }
    }

    /**
     * The status line is the primary signal, as it is for Fastly. Cloudflare's
     * envelope carries its own verdict, and an explicit `success: false` inside
     * a 2xx is still a rejected purge — but a 2xx without the envelope is
     * acceptance, so a body a gateway rewrote or a test double never sent does
     * not fail a purge the status line already confirmed.
     *
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function isSuccess(array $result): bool
    {
        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            return false;
        }

        if (\is_array($result['response']) && \array_key_exists('success', $result['response'])) {
            return $result['response']['success'] === true;
        }

        return true;
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     */
    private function formatError(array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['message'] ?? null;
        }

        $message ??= 'Unknown purge error';

        return 'Cloudflare purge failed with status ' . $result['statusCode'] . ': ' . $message;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $request = new RequestFactory()->json($method, $this->apiBase . $url, $body);
        $request = $request
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Cloudflare Adapter')
            ->withHeader(Header::AUTHORIZATION, 'Bearer ' . $this->apiToken)
            ->withHeader(Header::CONTENT_TYPE, ContentType::JSON);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = $contents;
        }

        return ['statusCode' => $response->getStatusCode(), 'response' => $decoded, 'error' => null];
    }
}
