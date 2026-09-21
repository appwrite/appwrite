<?php

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Certificates\Challenge;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

class FastlyTls implements Provider
{
    /**
     * Fastly's ACME DNS challenge: a CNAME at `_acme-challenge.<domain>` that
     * proves ownership without changing where the domain's traffic goes. The
     * other challenge types point the domain itself at Fastly.
     */
    public const string CHALLENGE_MANAGED_DNS = 'managed-dns';

    private readonly ClientInterface $client;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $tlsConfigurationId,
        private readonly string $certificateAuthority = 'certainly',
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.fastly.com',
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            $subscription = $this->createSubscription($domain);
        } elseif ($this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED) {
            $subscription = $this->retrySubscription($subscription['resource']['id']);
        }

        return $this->extractRenewDate($subscription);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    /**
     * @throws Certificate When Fastly waits on the domain owner to prove ownership, or has stopped trying.
     */
    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return Status::UNKNOWN;
        }

        $status = $this->mapStatus($subscription['resource']['attributes']['state'] ?? '');

        // An issued certificate needs nothing from the domain owner, whatever
        // an authorization left over from an earlier order still says.
        if ($status === Status::ISSUED || $status === Status::UNKNOWN) {
            return $status;
        }

        // Fastly keeps the subscription `pending` while an authorization is
        // `blocked` on a DNS record only the domain owner can add, so the
        // subscription state alone cannot tell waiting from progress.
        $authorizations = $this->findAuthorizations($subscription, $domain);
        if ($status !== Status::FAILED && !$this->isBlocked($authorizations)) {
            return $status;
        }

        $status = $status === Status::FAILED ? Status::FAILED : Status::BLOCKED;
        $challenges = $this->extractChallenges($authorizations);
        $warnings = $this->extractWarnings($authorizations);

        throw new Certificate($this->describe($domain, $status, $challenges, $warnings), $status, $challenges, $warnings);
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return true;
        }

        return $this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return;
        }

        $result = $this->request(
            'DELETE',
            '/tls/subscriptions/' . $subscription['resource']['id'] . '?force=true',
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to delete Fastly TLS subscription', $result));
        }
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}|null
     */
    private function findSubscription(string $domain): ?array
    {
        $query = http_build_query([
            'filter[tls_domains.id]' => $domain,
            'include' => 'tls_certificates,tls_authorizations',
            'page[size]' => 1,
        ]);

        $result = $this->request('GET', '/tls/subscriptions?' . $query);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to fetch Fastly TLS subscriptions', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscriptions response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;
        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS subscriptions response was missing its data list.');
        }

        $resource = $data[0] ?? null;
        if ($resource === null) {
            return null;
        }

        if (!\is_array($resource)) {
            throw new \RuntimeException('Fastly TLS subscription resource was malformed.');
        }

        $included = $result['response']['included'] ?? [];
        if (!\is_array($included)) {
            throw new \RuntimeException('Fastly TLS subscriptions response contained malformed included resources.');
        }

        return ['resource' => $resource, 'included' => array_values(array_filter($included, is_array(...)))];
    }

    /**
     * The subscription's TLS authorizations for the domain, as Fastly included
     * them alongside the subscription.
     *
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     * @return list<array<string, mixed>>
     */
    private function findAuthorizations(array $subscription, string $domain): array
    {
        $ids = null;
        $references = $subscription['resource']['relationships']['tls_authorizations']['data'] ?? null;
        if (\is_array($references)) {
            $ids = [];
            foreach ($references as $reference) {
                if (\is_array($reference) && \is_string($reference['id'] ?? null)) {
                    $ids[] = $reference['id'];
                }
            }
        }

        $authorizations = [];
        foreach ($subscription['included'] as $included) {
            if (($included['type'] ?? null) !== 'tls_authorization') {
                continue;
            }

            if ($ids !== null && !\in_array($included['id'] ?? null, $ids, true)) {
                continue;
            }

            $authorizedDomain = $included['relationships']['tls_domain']['data']['id'] ?? null;
            if (\is_string($authorizedDomain) && strcasecmp($authorizedDomain, $domain) !== 0) {
                continue;
            }

            $authorizations[] = $included;
        }

        return $authorizations;
    }

    /**
     * @param list<array<string, mixed>> $authorizations
     */
    private function isBlocked(array $authorizations): bool
    {
        foreach ($authorizations as $authorization) {
            $state = $authorization['attributes']['state'] ?? null;
            if (\is_string($state) && strtolower($state) === 'blocked') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $authorizations
     * @return list<Challenge>
     */
    private function extractChallenges(array $authorizations): array
    {
        $challenges = [];
        foreach ($authorizations as $authorization) {
            $reported = $authorization['attributes']['challenges'] ?? null;
            if (!\is_array($reported)) {
                continue;
            }

            foreach ($reported as $challenge) {
                if (!\is_array($challenge)) {
                    continue;
                }

                $type = $challenge['type'] ?? null;
                $recordType = $challenge['record_type'] ?? null;
                $recordName = $challenge['record_name'] ?? null;
                $values = $challenge['values'] ?? null;
                if (!\is_string($type)) {
                    continue;
                }
                if (!\is_string($recordType)) {
                    continue;
                }
                if (!\is_string($recordName)) {
                    continue;
                }
                if ($recordName === '') {
                    continue;
                }
                if (!\is_array($values)) {
                    continue;
                }

                $values = array_values(array_filter($values, static fn(mixed $value): bool => \is_string($value) && $value !== ''));
                if ($values === []) {
                    continue;
                }

                $challenges[] = new Challenge($type, strtoupper($recordType), $recordName, $values);
            }
        }

        return $challenges;
    }

    /**
     * @param list<array<string, mixed>> $authorizations
     * @return list<string>
     */
    private function extractWarnings(array $authorizations): array
    {
        $warnings = [];
        foreach ($authorizations as $authorization) {
            $reported = $authorization['attributes']['warnings'] ?? null;
            if (!\is_array($reported)) {
                continue;
            }

            foreach ($reported as $warning) {
                $instructions = \is_array($warning) ? ($warning['instructions'] ?? null) : null;
                if (!\is_string($instructions)) {
                    continue;
                }

                $instructions = trim($instructions);
                if ($instructions !== '' && !\in_array($instructions, $warnings, true)) {
                    $warnings[] = $instructions;
                }
            }
        }

        return $warnings;
    }

    /**
     * @param list<Challenge> $challenges
     * @param list<string> $warnings
     */
    private function describe(string $domain, string $status, array $challenges, array $warnings): string
    {
        $message = $status === Status::FAILED
            ? "Fastly stopped trying to issue a certificate for {$domain}."
            : "Fastly cannot issue a certificate for {$domain} until its ownership is verified.";

        foreach ($warnings as $warning) {
            $message .= ' ' . rtrim($warning, '.') . '.';
        }

        if (\count($challenges) === 1) {
            $message .= " Create a {$challenges[0]->recordType} record for {$challenges[0]->recordName} pointing to " . implode(' or ', $challenges[0]->values) . '.';
        } elseif ($challenges !== []) {
            $message .= ' Create one of these DNS records: ' . implode('; ', array_map(
                static fn(Challenge $challenge): string => "{$challenge->recordType} {$challenge->recordName} -> " . implode(' or ', $challenge->values),
                $challenges,
            )) . '.';
        }

        return $message;
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function createSubscription(string $domain): array
    {
        $relationships = [
            'tls_domains' => [
                'data' => [[
                    'type' => 'tls_domain',
                    'id' => $domain,
                ]],
            ],
        ];

        if ($this->tlsConfigurationId !== '') {
            $relationships['common_name'] = [
                'data' => [
                    'type' => 'tls_domain',
                    'id' => $domain,
                ],
            ];
            $relationships['tls_configuration'] = [
                'data' => [
                    'type' => 'tls_configuration',
                    'id' => $this->tlsConfigurationId,
                ],
            ];
        }

        $result = $this->request('POST', '/tls/subscriptions', [
            'data' => [
                'type' => 'tls_subscription',
                'attributes' => [
                    'certificate_authority' => $this->certificateAuthority,
                ],
                'relationships' => $relationships,
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to create Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscription response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS subscription response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? array_values(array_filter($included, is_array(...))) : []];
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function retrySubscription(string $subscriptionId): array
    {
        // A subscription fails on a renewal while the certificate it issued
        // earlier is still deployed, which Fastly calls an active domain, and
        // Fastly refuses to edit such a subscription without `force`. A retry
        // keeps the domain and only asks for issuance again, so the deployed
        // certificate keeps serving while the retry runs.
        $result = $this->request('PATCH', '/tls/subscriptions/' . $subscriptionId . '?force=true', [
            'data' => [
                'id' => $subscriptionId,
                'type' => 'tls_subscription',
                'attributes' => [
                    'state' => 'retry',
                ],
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to retry Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS retry response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS retry response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? array_values(array_filter($included, is_array(...))) : []];
    }

    /**
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     */
    private function extractRenewDate(array $subscription): ?string
    {
        $resource = $subscription['resource'];
        $state = $this->mapStatus($resource['attributes']['state'] ?? '');

        if ($state !== Status::ISSUED && $state !== Status::RENEWING) {
            return null;
        }

        $relationship = $resource['relationships']['tls_certificates']['data'] ?? [];
        $certificateIds = [];
        if (\is_array($relationship)) {
            foreach ($relationship as $reference) {
                if (\is_array($reference) && \is_string($reference['id'] ?? null)) {
                    $certificateIds[] = $reference['id'];
                }
            }
        }

        $dates = [];
        foreach ($subscription['included'] as $included) {
            if (($included['type'] ?? null) !== 'tls_certificate') {
                continue;
            }
            if (!\in_array($included['id'] ?? null, $certificateIds, true)) {
                continue;
            }
            $notAfter = $included['attributes']['not_after'] ?? null;
            if (\is_string($notAfter) && $notAfter !== '') {
                $dates[] = $notAfter;
            }
        }

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn(string $left, string $right): int => strtotime($right) <=> strtotime($left));
        $date = new \DateTimeImmutable($dates[0]);

        return $date->modify('-30 days')->format('Y-m-d H:i:s.v');
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
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Fastly TLS Provider')
            ->withHeader('Fastly-Key', $this->apiToken)
            ->withHeader(Header::ACCEPT, 'application/vnd.api+json')
            ->withHeader(Header::CONTENT_TYPE, 'application/vnd.api+json');

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
     */
    private function formatError(string $prefix, array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['detail']
                ?? $result['response']['errors'][0]['title']
                ?? $result['response']['msg']
                ?? null;
        }

        $message ??= 'Unknown Fastly TLS error';

        return $prefix . ' with status ' . $result['statusCode'] . ': ' . $message;
    }

    private function mapStatus(string $state): string
    {
        return match (strtolower($state)) {
            'pending' => Status::PENDING,
            'processing' => Status::PROCESSING,
            'issued' => Status::ISSUED,
            'renewing' => Status::RENEWING,
            'failed' => Status::FAILED,
            default => Status::UNKNOWN,
        };
    }
}
