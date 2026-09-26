<?php

declare(strict_types=1);

namespace Utopia\Domains;

use Utopia\Domains\Registrar\Adapter as RegistrarAdapter;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Domain;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\UpdateDetails;

class Registrar
{
    /**
     * Registration Types
     */
    public const REG_TYPE_NEW = 'new';
    public const REG_TYPE_TRANSFER = 'transfer';
    public const REG_TYPE_RENEWAL = 'renewal';
    public const REG_TYPE_TRADE = 'trade';

    /**
     * Constructor
     *
     * @param RegistrarAdapter $adapter The registrar adapter to use
     * @param array $defaultNameservers Default nameservers for domain registration
     * @param Cache|null $cache Optional cache instance
     * @param int $connectTimeout Connection timeout in seconds
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(
        protected RegistrarAdapter $adapter,
        array $defaultNameservers = [],
        ?Cache $cache = null,
        int $connectTimeout = 5,
        int $timeout = 10,
    ) {
        if ($defaultNameservers !== []) {
            $this->adapter->setDefaultNameservers($defaultNameservers);
        }

        if ($cache instanceof \Utopia\Domains\Cache) {
            $this->adapter->setCache($cache);
        }

        $this->adapter->setConnectTimeout($connectTimeout);
        $this->adapter->setTimeout($timeout);
    }

    /**
     * Get the name of the adapter
     */
    public function getName(): string
    {
        return $this->adapter->getName();
    }

    /**
     * Check if domains are available
     *
     * @param array<string> $domains Domain names to check
     * @return array<string, bool> Availability keyed by domain name
     */
    public function available(array $domains): array
    {
        return $this->adapter->available($domains);
    }

    /**
     * Purchase a domain
     *
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string
    {
        return $this->adapter->purchase($domain, $contacts, $periodYears, $nameservers, $autorenewEnabled, $purchasePrice);
    }

    /**
     * Suggest domain names
     */
    public function suggest(array|string $query, array $tlds = [], ?int $limit = null, ?string $filterType = null, ?int $priceMax = null, ?int $priceMin = null): array
    {
        return $this->adapter->suggest($query, $tlds, $limit, $filterType, $priceMax, $priceMin);
    }

    /**
     * Get the list of top-level domains
     */
    public function tlds(): array
    {
        return $this->adapter->tlds();
    }

    /**
     * Get the details of a domain
     */
    public function getDomain(string $domain): Domain
    {
        return $this->adapter->getDomain($domain);
    }

    /**
     * Update the details of a domain
     */
    public function updateDomain(string $domain, UpdateDetails $details): bool
    {
        return $this->adapter->updateDomain($domain, $details);
    }

    /**
     * Update nameservers of a domain
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        return $this->adapter->updateNameservers($domain, $nameservers);
    }

    /**
     * Get the price of a domain
     */
    public function getPrice(string $domain, int $periodYears = 1, string $regType = self::REG_TYPE_NEW, int $ttl = 3600): Price
    {
        return $this->adapter->getPrice($domain, $periodYears, $regType, $ttl);
    }

    /**
     * Renewal a domain
     */
    public function renew(string $domain, int $periodYears): Renewal
    {
        return $this->adapter->renew($domain, $periodYears);
    }

    /**
     * Transfer a domain
     *
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string
    {
        return $this->adapter->transfer($domain, $authCode, $purchasePrice);
    }

    /**
     * Get the auth code of a domain
     */
    public function getAuthCode(string $domain): string
    {
        return $this->adapter->getAuthCode($domain);
    }

    /**
     * Cancel pending purchase orders
     */
    public function cancelPurchase(): bool
    {
        return $this->adapter->cancelPurchase();
    }

    /**
     * Check transfer status for a domain
     */
    public function checkTransferStatus(string $domain): TransferStatus
    {
        return $this->adapter->checkTransferStatus($domain);
    }
}
