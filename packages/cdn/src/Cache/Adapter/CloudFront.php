<?php

declare(strict_types=1);

namespace Utopia\Cdn\Cache\Adapter;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Aws\Signature;
use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Invalidates content on a CloudFront multi-tenant distribution.
 *
 * A multi-tenant distribution is invalidated one distribution tenant at a time, so every purge
 * names a tenant. Domain purges find it themselves, from the domain; key and zone purges carry no
 * domain and use the tenant this adapter was constructed for.
 *
 * Cache keys are CloudFront cache tags: the values the origin lists in the distribution's
 * CacheTagConfig header. They travel in the same invalidation batch as paths, prefixed with "#".
 */
class CloudFront implements Adapter
{
    /**
     * CloudFront signs every request in us-east-1, whatever edge serves the content.
     */
    public const string SIGNING_SERVICE = 'cloudfront';

    public const string SIGNING_REGION = 'us-east-1';

    public const string API_VERSION = '2020-05-31';

    /**
     * Paths per invalidation. CloudFront's default quota is a rate: 150 paths or tags processed per
     * second. Sending at most 150 per request, one request per batchInterval, keeps a purge at that
     * rate; a request CloudFront still throttles is retried, see THROTTLE_RETRIES.
     */
    public const int PATHS_PER_PURGE = 150;

    /**
     * Tags per invalidation. A tag counts as one path towards the same quota.
     */
    public const int KEYS_PER_PURGE = 150;

    /**
     * Times one invalidation is resent after CloudFront throttles it, waiting twice as long before
     * each attempt, starting from batchInterval.
     */
    public const int THROTTLE_RETRIES = 3;

    /**
     * Error codes that mean "too much at once, try again", not "this request is wrong".
     */
    private const array THROTTLE_CODES = ['Throttling', 'TooManyInvalidationsInProgress', 'TooManyRequests'];

    /**
     * CloudFront's documented ceiling for one cache tag.
     */
    public const int KEY_MAX_LENGTH = 256;

    private readonly ClientInterface $client;

    private readonly Signature $signature;

    /**
     * @param string $domainKeyPrefix Prefix of the per-domain cache tag the origin attaches. Pass '' when the tag is the bare hostname.
     * @param string|null $tenantId Distribution tenant that key and zone purges invalidate. Domain purges look their tenant up instead.
     * @param int $batchInterval Milliseconds to wait between two invalidation requests of one purge, keeping it under CloudFront's per-second quota.
     */
    public function __construct(
        string $accessKeyId,
        string $secretAccessKey,
        private readonly string $domainKeyPrefix,
        private readonly ?string $tenantId = null,
        ?string $sessionToken = null,
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://cloudfront.amazonaws.com',
        private readonly int $batchInterval = 1000,
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
        $this->signature = new Signature($accessKeyId, $secretAccessKey, $sessionToken);
    }

    /**
     * Invalidates the paths on the tenant serving the domain. A tenant's cache is shared by every
     * domain on it, so the same paths are invalidated for the tenant's other domains too.
     */
    public function purgePaths(string $domain, array $paths): void
    {
        $domain = Domain::validate($domain);
        $paths = Domain::validatePaths($paths);

        if ($paths === []) {
            return;
        }

        $tenantId = $this->findTenant($domain);

        if ($tenantId === null) {
            return;
        }

        $this->invalidate($tenantId, array_map($this->encodePath(...), $paths), self::PATHS_PER_PURGE);
    }

    /**
     * Invalidates the domain's cache tag on the tenant serving it, leaving the tenant's other
     * domains cached.
     */
    public function purgeDomain(string $domain): void
    {
        $domain = Domain::validate($domain);
        $tag = $this->tag($this->domainKeyPrefix . $domain);
        $tenantId = $this->findTenant($domain);

        if ($tenantId === null) {
            return;
        }

        $this->invalidate($tenantId, [$tag], self::KEYS_PER_PURGE);
    }

    public function purgeKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $tenantId = $this->requireTenant('cache key purging');

        $this->invalidate($tenantId, array_map($this->tag(...), $keys), self::KEYS_PER_PURGE);
    }

    /**
     * Invalidates every object on the tenant with a single "/*" wildcard path.
     */
    public function purgeZone(): void
    {
        $tenantId = $this->requireTenant('zone purging');

        $this->invalidate($tenantId, ['/*'], self::PATHS_PER_PURGE);
    }

    /**
     * Reported as an unsupported operation rather than a failure, as on Fastly without a service ID:
     * domain purges still work, so this is a gap in configuration, not a purge that went wrong.
     */
    private function requireTenant(string $operation): string
    {
        if ($this->tenantId === null || $this->tenantId === '') {
            throw new UnsupportedOperation('CloudFront distribution tenant ID is required for ' . $operation . '.');
        }

        return $this->tenantId;
    }

    /**
     * The tenant serving the domain, or null when no tenant does. A domain that is not on
     * CloudFront has nothing cached there, so its purge has nothing to do rather than failing.
     */
    private function findTenant(string $domain): ?string
    {
        $result = $this->request(Method::GET, '/distribution-tenant?domain=' . rawurlencode($domain));

        if ($result['statusCode'] === 404 && $this->errorCode($result) === 'EntityNotFound') {
            return null;
        }

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('tenant lookup', $result));
        }

        $id = $result['response'] instanceof \SimpleXMLElement ? (string) $result['response']->Id : '';

        if ($id === '') {
            throw new \RuntimeException('CloudFront tenant lookup for ' . $domain . ' returned no tenant ID.');
        }

        return $id;
    }

    /**
     * Sends the items in batches, pausing between them so a large purge spreads over as many
     * seconds as CloudFront's quota needs rather than being throttled part-way through.
     *
     * @param array<int, string> $items
     * @param int<1, max> $batchSize
     */
    private function invalidate(string $tenantId, array $items, int $batchSize): void
    {
        $path = '/distribution-tenant/' . rawurlencode($tenantId) . '/invalidation';

        foreach (array_chunk($items, $batchSize) as $index => $batch) {
            if ($index > 0) {
                $this->pause($this->batchInterval);
            }

            // Built once, so a retried batch keeps its caller reference.
            $body = $this->invalidationBatch($batch);
            $result = $this->request(Method::POST, $path, $body);

            for ($retry = 0; $retry < self::THROTTLE_RETRIES && $this->isThrottled($result); $retry++) {
                $this->pause($this->batchInterval * (2 ** $retry));
                $result = $this->request(Method::POST, $path, $body);
            }

            if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
                throw new \RuntimeException($this->formatError('invalidation', $result));
            }
        }
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,error:string|null} $result
     */
    private function isThrottled(array $result): bool
    {
        return $result['statusCode'] === 429 || \in_array($this->errorCode($result), self::THROTTLE_CODES, true);
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * @param array<int, string> $items
     */
    private function invalidationBatch(array $items): string
    {
        $paths = implode('', array_map(
            static fn (string $item): string => '<Path>' . htmlspecialchars($item, \ENT_XML1 | \ENT_QUOTES, 'UTF-8') . '</Path>',
            $items,
        ));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/' . self::API_VERSION . '/">'
            . '<CallerReference>' . bin2hex(random_bytes(16)) . '</CallerReference>'
            . '<Paths><Items>' . $paths . '</Items><Quantity>' . \count($items) . '</Quantity></Paths>'
            . '</InvalidationBatch>';
    }

    /**
     * A cache tag is an invalidation path starting with "#". Tags CloudFront would never have
     * stored are refused before sending, rather than failing the whole batch they travel in.
     */
    private function tag(string $key): string
    {
        $length = \strlen($key);

        if ($length === 0 || $length > self::KEY_MAX_LENGTH) {
            throw new \InvalidArgumentException('CloudFront cache tags must be 1 to ' . self::KEY_MAX_LENGTH . ' characters long.');
        }

        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($key[$i]);

            if ($byte < 33 || $byte > 126 || $key[$i] === ',') {
                throw new \InvalidArgumentException('CloudFront cache tags may contain only visible ASCII characters other than ",".');
            }
        }

        return '#' . $key;
    }

    /**
     * CloudFront expects unsafe characters in an invalidation path to be percent-encoded. "*" stays
     * literal, because a trailing "*" is a wildcard, and so does a "%" that already starts an
     * escape; any other "%" is a literal percent sign and is encoded.
     */
    private function encodePath(string $path): string
    {
        $encoded = '';
        $length = \strlen($path);

        for ($i = 0; $i < $length; $i++) {
            $character = $path[$i];

            if ($character === '%') {
                $escape = substr($path, $i + 1, 2);
                $encoded .= \strlen($escape) === 2 && ctype_xdigit($escape) ? '%' : '%25';

                continue;
            }

            $encoded .= ctype_alnum($character) || str_contains('-._~/?=&:+*', $character)
                ? $character
                : rawurlencode($character);
        }

        return $encoded;
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,error:string|null} $result
     */
    private function errorCode(array $result): ?string
    {
        if (!$result['response'] instanceof \SimpleXMLElement) {
            return null;
        }

        $code = (string) $result['response']->Error->Code;

        return $code === '' ? null : $code;
    }

    /**
     * @param array{statusCode:int,response:\SimpleXMLElement|string|null,error:string|null} $result
     */
    private function formatError(string $operation, array $result): string
    {
        $message = $result['error'] ?? null;

        if ($result['response'] instanceof \SimpleXMLElement) {
            $detail = (string) $result['response']->Error->Message;
            $code = $this->errorCode($result);

            if ($detail !== '' || $code !== null) {
                $message ??= trim(($code ?? '') . ': ' . $detail, ': ');
            }
        }

        $message ??= 'Unknown purge error';

        return 'CloudFront ' . $operation . ' failed with status ' . $result['statusCode'] . ': ' . $message;
    }

    /**
     * @return array{statusCode:int,response:\SimpleXMLElement|string|null,error:string|null}
     */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $factory = new RequestFactory();
        $url = $this->apiBase . '/' . self::API_VERSION . $path;
        $request = $body === null
            ? $factory->createRequest($method, $url)
            : $factory->xml($method, $url, $body);

        $request = $this->signature->sign(
            $request
                ->withHeader(Header::USER_AGENT, 'Utopia CDN CloudFront Adapter')
                ->withHeader(Header::ACCEPT, 'application/xml'),
            self::SIGNING_SERVICE,
            self::SIGNING_REGION,
        );

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();
        $decoded = $contents === '' ? false : simplexml_load_string($contents, options: \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);

        return [
            'statusCode' => $response->getStatusCode(),
            'response' => $decoded === false ? $contents : $decoded,
            'error' => null,
        ];
    }
}
