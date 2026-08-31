<?php

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Cloudflare implements Provider
{
    private readonly ClientInterface $client;

    public function __construct(
        private readonly string $zoneId,
        private readonly string $apiToken,
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.cloudflare.com/client/v4',
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $result = $this->request(Method::POST, $this->hostnamesPath(), [
            'hostname' => $domain,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
                'wildcard' => false,
            ],
        ]);

        if ($this->isDuplicate($result)) {
            return null;
        }

        $this->assertSuccess('create Cloudflare custom hostname', $result, [201]);

        return null;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        Domain::validate($domain);

        return true;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        throw new UnsupportedOperation('Certificate status retrieval is not supported by the Cloudflare provider.');
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return $this->findHostname(Domain::validate($domain)) === null;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $hostname = $this->findHostname(Domain::validate($domain));
        if ($hostname === null) {
            return;
        }

        $id = $hostname['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw new \RuntimeException('Cloudflare custom hostname response was missing an ID.');
        }

        $result = $this->request(Method::DELETE, $this->hostnamesPath() . '/' . rawurlencode($id));
        $this->assertSuccess('delete Cloudflare custom hostname', $result);
    }

    /** @return array<string, mixed>|null */
    private function findHostname(string $domain): ?array
    {
        $result = $this->request(Method::GET, $this->hostnamesPath() . '?' . http_build_query(['hostname' => $domain]));
        $this->assertSuccess('fetch Cloudflare custom hostnames', $result);

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Cloudflare custom hostname response was not valid JSON.');
        }

        $hostnames = $result['response']['result'] ?? null;
        if (!\is_array($hostnames)) {
            throw new \RuntimeException('Cloudflare custom hostname response was missing its result list.');
        }

        foreach ($hostnames as $hostname) {
            if (\is_array($hostname) && ($hostname['hostname'] ?? null) === $domain) {
                return $hostname;
            }
        }

        return null;
    }

    private function hostnamesPath(): string
    {
        return '/zones/' . $this->zoneId . '/custom_hostnames';
    }

    /** @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result */
    private function isDuplicate(array $result): bool
    {
        return \is_array($result['response']) && (($result['response']['errors'][0]['code'] ?? null) === 1406);
    }

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     * @param array<int, int>|null $expectedStatuses
     */
    private function assertSuccess(string $operation, array $result, ?array $expectedStatuses = null): void
    {
        $httpSuccess = $expectedStatuses === null
            ? $result['statusCode'] >= 200 && $result['statusCode'] < 300
            : \in_array($result['statusCode'], $expectedStatuses, true);
        $envelopeSuccess = !\is_array($result['response']) || !\array_key_exists('success', $result['response']) || $result['response']['success'] === true;

        if (!$httpSuccess || !$envelopeSuccess) {
            $message = $result['error'];
            if (\is_array($result['response'])) {
                $message ??= $result['response']['errors'][0]['message'] ?? null;
            }

            throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?? 'Unknown Cloudflare error'));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:array<string, mixed>|string|null,error:string|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $factory = new RequestFactory();
        $request = $body === null
            ? $factory->createRequest($method, $this->apiBase . $path)
            : $factory->json($method, $this->apiBase . $path, $body);
        $request = $request
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Cloudflare Certificates Provider')
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
