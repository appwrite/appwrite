<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Domains\Adapter as DomainsAdapter;
use Utopia\Domains\Cache;
use Utopia\Domains\Registrar;

abstract class Adapter extends DomainsAdapter
{
    /**
     * Default nameservers for domain registration
     */
    protected array $defaultNameservers = [];

    /**
     * Cache instance
     */
    protected ?Cache $cache = null;

    /**
     * Connection timeout in seconds
     */
    protected int $connectTimeout = 5;

    /**
     * Request timeout in seconds
     */
    protected int $timeout = 10;

    /**
     * An injected transport owns its timeout, TLS, redirect and connection settings.
     */
    protected ?ClientInterface $client = null;

    private ?Client $defaultClient = null;

    protected function getHttpClient(): ClientInterface
    {
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }

        $this->defaultClient ??= new Client(new CurlAdapter());

        return $this->defaultClient
            ->withConnectTimeout($this->connectTimeout)
            ->withTimeout($this->timeout)
            ->withSslVerification(true)
            ->withFollowRedirects(false);
    }

    /**
     * Set default nameservers
     */
    public function setDefaultNameservers(array $nameservers): void
    {
        $this->defaultNameservers = $nameservers;
    }

    /**
     * Set cache instance
     */
    public function setCache(?Cache $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Set connection timeout for the default transport. Injected clients are unchanged.
     */
    public function setConnectTimeout(int $connectTimeout): void
    {
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Set request timeout for the default transport. Injected clients are unchanged.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Get the name of the adapter
     */
    abstract public function getName(): string;

    /**
     * Check if domains are available
     *
     * @param array<string> $domains Domain names to check
     * @return array<string, bool> Availability keyed by domain name
     */
    abstract public function available(array $domains): array;

    /**
     * Purchase a domain
     *
     * @param  float|null  $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    abstract public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string;

    /**
     * Suggest domain names
     *
     * @param  array  $query
     * @param  string|null $filterType Filter results by type: 'premium', 'suggestion', or null for both
     */
    abstract public function suggest(array|string $query, array $tlds = [], ?int $limit = null, ?string $filterType = null, ?int $priceMax = null, ?int $priceMin = null): array;

    /**
     * Get the TLDs supported by the adapter
     */
    abstract public function tlds(): array;

    /**
     * Get the domain information
     */
    abstract public function getDomain(string $domain): Domain;

    /**
     * Update the domain information
     */
    abstract public function updateDomain(string $domain, UpdateDetails $details): bool;

    /**
     * Update the nameservers for a domain
     *
     * @throws \Exception
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        throw new \Exception('Method not implemented');
    }

    /**
     * Get the price of a domain
     */
    abstract public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price;

    /**
     * Renew a domain
     */
    abstract public function renew(string $domain, int $periodYears): Renewal;

    /**
     * Transfer a domain
     *
     * @param  float|null  $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    abstract public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string;

    /**
     * Get the authorization code for an EPP domain
     */
    abstract public function getAuthCode(string $domain): string;

    /**
     * Check transfer status for a domain
     */
    abstract public function checkTransferStatus(string $domain): TransferStatus;

    /**
     * Cancel pending purchase orders
     */
    abstract public function cancelPurchase(): bool;
}
