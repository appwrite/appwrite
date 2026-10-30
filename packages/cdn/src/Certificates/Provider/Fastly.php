<?php

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Manages Fastly domains together with their TLS subscriptions.
 *
 * Fastly's domain-management API owns versionless domains. Domains on a classic
 * service version have to be removed by cloning and activating that version
 * before their TLS subscription can be deleted.
 *
 * A domain record that names no service is left alone while a routing
 * configuration or the configured service's active version serves it. When
 * neither does, the record is detached: issuing reassigns it to the configured
 * service once its certificate is issued, and deleting leaves it alone.
 * isDetached() states what that check does not cover.
 */
class Fastly implements Provider
{
    /**
     * Pages of 100 records read when looking up a domain. Fastly's `fqdn`
     * filter is a partial match, so a name contained in many others spans
     * several pages before its exact record turns up.
     */
    private const int DOMAIN_PAGES = 10;

    private readonly ClientInterface $client;

    private readonly FastlyTls $tls;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $serviceId,
        string $certificateAuthority = 'certainly',
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.fastly.com',
        private readonly int $deploymentPollAttempts = 10,
        private readonly int $deploymentPollIntervalMilliseconds = 5000,
        string $tlsConfigurationId = '',
    ) {
        if ($this->deploymentPollAttempts < 1) {
            throw new \InvalidArgumentException('Deployment poll attempts must be at least one.');
        }

        if ($this->deploymentPollIntervalMilliseconds < 0) {
            throw new \InvalidArgumentException('Deployment poll interval cannot be negative.');
        }

        $this->client = $client ?? new Client(new CurlAdapter());
        $this->tls = new FastlyTls(
            apiToken: $this->apiToken,
            tlsConfigurationId: $tlsConfigurationId,
            certificateAuthority: $certificateAuthority,
            client: $this->client,
            apiBase: $this->apiBase,
        );
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        if ($domainInfo === null) {
            $result = $this->request('POST', '/domain-management/v1/domains', [
                'fqdn' => $domain,
                'service_id' => $this->serviceId,
            ]);

            $errors = \is_array($result['response']) ? ($result['response']['errors'] ?? null) : null;
            $taken = $result['statusCode'] === 400 && \is_array($errors) && array_any(
                $errors,
                static function (mixed $error): bool {
                    $detail = \is_array($error) ? ($error['detail'] ?? null) : null;

                    return \is_string($detail) && str_contains($detail, 'already been taken');
                },
            );

            if (!$taken) {
                $this->assertSuccess('add Fastly domain', $result, [201]);

                return $this->tls->issueCertificate($certName, $domain, $domainType);
            }

            // The hostname was added since it was looked up, so carry on
            // with the record that holds it now.
            $domainInfo = $this->findDomain($domain)
                ?? throw new \RuntimeException('Fastly reports ' . $domain . ' as already taken, but no domain record in this account matches it. Another Fastly account or a classic service may hold it.');
        }

        $existingServiceId = $domainInfo['service_id'] ?? null;

        if ((!\is_string($existingServiceId) || $existingServiceId === '') && !$this->isDetached($domain, $domainInfo)) {
            // A classic domain cannot be moved without activating a new
            // service version, and a routing configuration serves its own
            // domains, so leave the existing certificate untouched.
            return null;
        }

        if ($existingServiceId !== $this->serviceId) {
            $domainId = $domainInfo['id'] ?? null;
            if (!\is_string($domainId) || $domainId === '') {
                throw new \RuntimeException('Fastly domain response was missing its ID.');
            }

            // Finish the certificate lifecycle while ownership is still
            // unchanged. A TLS failure must not move a live hostname, and a
            // detached record may still be another service's classic domain.
            $renewDate = $this->tls->issueCertificate($certName, $domain, $domainType);

            try {
                $status = $this->tls->getCertificateStatus($domain, $domainType);
            } catch (Certificate $exception) {
                if (!$exception->isBlocked()) {
                    throw $exception;
                }

                // Waiting on the domain owner: nothing is issued yet, so
                // the hostname stays where it is.
                return $renewDate;
            }

            if (!\in_array($status, [Status::ISSUED, Status::RENEWING], true)) {
                return $renewDate;
            }

            $result = $this->request(
                'PATCH',
                '/domain-management/v1/domains/' . rawurlencode($domainId),
                ['service_id' => $this->serviceId],
            );
            $this->assertSuccess('reassign Fastly domain', $result);

            return $renewDate;
        }

        return $this->tls->issueCertificate($certName, $domain, $domainType);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return $this->tls->getCertificateStatus(Domain::validate($domain), $domainType);
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        if ($domainInfo === null) {
            return true;
        }

        $serviceId = $domainInfo['service_id'] ?? null;
        if (!\is_string($serviceId) || $serviceId === '') {
            return $this->isDetached($domain, $domainInfo);
        }

        if ($serviceId !== $this->serviceId) {
            return true;
        }

        return $this->tls->isRenewRequired($domain, $domainType);
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        if ($domainInfo === null) {
            $this->tls->deleteCertificate($domain, $domainType);
            return;
        }

        $serviceId = $domainInfo['service_id'] ?? null;
        if (!\is_string($serviceId) || $serviceId === '') {
            // A routing configuration serves the hostname, so neither a version entry nor its TLS is ours to remove
            $routingConfigurationId = $domainInfo['routing_configuration_id'] ?? null;
            if ($routingConfigurationId !== null && $routingConfigurationId !== '') {
                return;
            }

            $this->deleteClassicDomain($domain, $domainType);
            return;
        }

        if ($serviceId !== $this->serviceId) {
            return;
        }

        $this->deleteVersionlessDomain($domainInfo, $domainType);
    }

    /**
     * The domain-management record for exactly this domain, read page by page
     * because the `fqdn` filter also returns every name that contains it.
     *
     * @return array<string, mixed>|null
     */
    private function findDomain(string $domain): ?array
    {
        $query = ['fqdn' => $domain, 'limit' => 100];

        for ($page = 1; $page <= self::DOMAIN_PAGES; $page++) {
            $result = $this->request('GET', '/domain-management/v1/domains?' . http_build_query($query));
            $this->assertSuccess('fetch Fastly domains', $result);

            if (!\is_array($result['response'])) {
                throw new \RuntimeException('Fastly domains response was not valid JSON.');
            }

            $domains = $result['response']['data'] ?? null;
            if (!\is_array($domains)) {
                throw new \RuntimeException('Fastly domains response was missing its data list.');
            }

            foreach ($domains as $candidate) {
                if (\is_array($candidate) && ($candidate['fqdn'] ?? null) === $domain) {
                    return $candidate;
                }
            }

            $meta = $result['response']['meta'] ?? null;
            $cursor = \is_array($meta) ? ($meta['next_cursor'] ?? null) : null;
            if (!\is_string($cursor) || $cursor === '') {
                return null;
            }

            $query['cursor'] = $cursor;
        }

        // Reporting the domain as missing here would let a caller create or
        // delete around a record that only sits on a later page.
        throw new \RuntimeException('Fastly domains matching ' . $domain . ' span more than ' . self::DOMAIN_PAGES . ' pages without an exact match.');
    }

    /**
     * Whether a domain record with no service is detached: neither a routing
     * configuration nor a service version serves it, so Fastly answers the
     * hostname with an unknown-domain page while its TLS still reports issued.
     *
     * Only the configured service's active version is read. Versions of other
     * services are not, so a classic domain that another service holds, with a
     * record that names no service, is treated as detached here. That is why
     * deleting leaves a detached record alone, and issuing moves it only once
     * its certificate is issued.
     *
     * @param array<string, mixed> $domainInfo
     */
    private function isDetached(string $domain, array $domainInfo): bool
    {
        $routingConfigurationId = $domainInfo['routing_configuration_id'] ?? null;
        if ($routingConfigurationId !== null && $routingConfigurationId !== '') {
            return false;
        }

        return $this->findClassicVersion($domain) === null;
    }

    /** @param array<string, mixed> $domainInfo */
    private function deleteVersionlessDomain(array $domainInfo, ?string $domainType = null): void
    {
        $domainId = $domainInfo['id'] ?? null;
        $domain = $domainInfo['fqdn'] ?? null;

        if (!\is_string($domainId) || $domainId === '' || !\is_string($domain) || $domain === '') {
            throw new \RuntimeException('Fastly domain response was missing its ID or FQDN.');
        }

        $result = $this->request('DELETE', '/domain-management/v1/domains/' . rawurlencode($domainId));
        $this->assertSuccess('delete Fastly domain', $result, [204]);

        $this->tls->deleteCertificate($domain, $domainType);
    }

    private function deleteClassicDomain(string $domain, ?string $domainType): void
    {
        $currentVersion = $this->findClassicVersion($domain);

        if ($currentVersion === null) {
            // Classic domain records do not identify their service. An
            // account-wide FQDN match that is absent from this service may
            // belong to another service, whose TLS must remain untouched.
            return;
        }

        $servicePath = '/service/' . rawurlencode($this->serviceId) . '/version/';
        $result = $this->request('PUT', $servicePath . $currentVersion . '/clone');
        $this->assertSuccess('clone Fastly service version', $result);

        if (!\is_array($result['response']) || !\is_int($result['response']['number'] ?? null)) {
            throw new \RuntimeException('Fastly cloned service version was missing its number.');
        }
        $newVersion = $result['response']['number'];

        $result = $this->request('DELETE', $servicePath . $newVersion . '/domain/' . rawurlencode($domain));
        $this->assertSuccess('remove classic Fastly domain', $result, [200]);

        $result = $this->request('PUT', $servicePath . $newVersion . '/activate');
        $this->assertSuccess('activate Fastly service version', $result);

        $this->waitForDeployment($newVersion);
        $this->tls->deleteCertificate($domain, $domainType);
    }

    /**
     * The number of the configured service's active version when that version
     * holds the domain, or null when it does not.
     */
    private function findClassicVersion(string $domain): ?int
    {
        $result = $this->request('GET', '/service/' . rawurlencode($this->serviceId) . '/details');
        $this->assertSuccess('fetch Fastly service details', $result);

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly service details response was not valid JSON.');
        }

        if (!\array_key_exists('active_version', $result['response'])) {
            throw new \RuntimeException('Fastly service details response was missing its active version.');
        }

        // A service that was never activated has no version to hold the domain
        $activeVersion = $result['response']['active_version'];
        if ($activeVersion === null) {
            return null;
        }

        if (!\is_array($activeVersion)) {
            throw new \RuntimeException('Fastly service details response had an invalid active version.');
        }

        $domains = $activeVersion['domains'] ?? [];
        $containsDomain = \is_array($domains) && array_any(
            $domains,
            static fn (mixed $candidate): bool => \is_array($candidate) && ($candidate['name'] ?? null) === $domain,
        );

        if (!$containsDomain) {
            return null;
        }

        $number = $activeVersion['number'] ?? null;
        if (!\is_int($number)) {
            throw new \RuntimeException('Fastly active service version was missing its number.');
        }

        return $number;
    }

    private function waitForDeployment(int $version): void
    {
        $path = '/service/' . rawurlencode($this->serviceId) . '/version/' . $version;

        for ($attempt = 1; $attempt <= $this->deploymentPollAttempts; $attempt++) {
            $result = $this->request('GET', $path);
            $this->assertSuccess('fetch Fastly service version', $result);

            if (\is_array($result['response']) && ($result['response']['active'] ?? false) === true) {
                return;
            }

            if ($attempt < $this->deploymentPollAttempts && $this->deploymentPollIntervalMilliseconds > 0) {
                usleep($this->deploymentPollIntervalMilliseconds * 1000);
            }
        }

        throw new \RuntimeException('Fastly service version was not deployed after ' . $this->deploymentPollAttempts . ' attempts.');
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
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Fastly Certificates Provider')
            ->withHeader('Fastly-Key', $this->apiToken)
            ->withHeader(Header::ACCEPT, 'application/json');

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

    /**
     * @param array{statusCode:int,response:array<string, mixed>|string|null,error:string|null} $result
     * @param array<int, int>|null $expectedStatuses
     */
    private function assertSuccess(string $operation, array $result, ?array $expectedStatuses = null): void
    {
        $success = $expectedStatuses === null
            ? $result['statusCode'] >= 200 && $result['statusCode'] < 300
            : \in_array($result['statusCode'], $expectedStatuses, true);

        if ($success) {
            return;
        }

        $message = $result['error'];
        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['detail']
                ?? $result['response']['errors'][0]['title']
                ?? $result['response']['msg']
                ?? null;
        }

        throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?? 'Unknown Fastly error'));
    }
}
