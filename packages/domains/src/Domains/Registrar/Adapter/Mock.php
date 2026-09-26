<?php

namespace Utopia\Domains\Registrar\Adapter;

use DateTime;
use Utopia\Domains\Exception as DomainsException;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Domain;
use Utopia\Domains\Registrar\Exception\DomainTakenException;
use Utopia\Domains\Registrar\Exception\InvalidContactException;
use Utopia\Domains\Registrar\Exception\PriceNotFoundException;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\TransferStatusEnum;
use Utopia\Domains\Registrar\UpdateDetails;

class Mock extends Adapter
{
    /**
     * Mock API Response Codes
     */
    private const int RESPONSE_CODE_BAD_REQUEST = 400;
    private const int RESPONSE_CODE_NOT_FOUND = 404;
    private const int RESPONSE_CODE_INVALID_CONTACT = 465;
    private const int RESPONSE_CODE_DOMAIN_TAKEN = 485;

    /**
     * Domains that are considered unavailable/taken
     */
    protected array $takenDomains = [
        'google.com',
        'facebook.com',
        'amazon.com',
    ];

    /**
     * Domains that have been purchased in this mock session
     */
    protected array $purchasedDomains = [];

    /**
     * Domains that have been transferred in this mock session
     */
    protected array $transferredDomains = [];

    /**
     * Supported TLDs
     */
    protected array $supportedTlds = [
        'com',
        'net',
        'org',
        'io',
        'dev',
        'app',
    ];

    /**
     * Premium domains with their prices
     */
    protected array $premiumDomains = [
        'premium.com' => 5000.00,
        'business.com' => 10000.00,
        'shop.net' => 2500.00,
    ];

    public function getName(): string
    {
        return 'mock';
    }

    /**
     * Constructor
     *
     * @param array $takenDomains Optional list of domains to mark as taken
     * @param array $supportedTlds Optional list of supported TLDs
     * @param float $defaultPrice Optional default price for domains
     */
    public function __construct(
        array $takenDomains = [],
        array $supportedTlds = [],
        protected float $defaultPrice = 12.99,
    ) {
        if ($takenDomains !== []) {
            $this->takenDomains = array_merge($this->takenDomains, $takenDomains);
        }

        if ($supportedTlds !== []) {
            $this->supportedTlds = $supportedTlds;
        }
    }

    /**
     * Check if domains are available for registration
     *
     * @param array<string> $domains Domain names to check
     * @return array<string, bool> Availability keyed by domain name
     */
    public function available(array $domains): array
    {
        $availability = [];

        foreach (array_unique($domains) as $domain) {
            $availability[$domain] = !\in_array($domain, $this->takenDomains)
                && !\in_array($domain, $this->purchasedDomains);
        }

        return $availability;
    }

    /**
     * Purchase a domain
     *
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     * @throws DomainTakenException
     * @throws InvalidContactException
     */
    public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string
    {
        if (!$this->available([$domain])[$domain]) {
            throw new DomainTakenException("Domain {$domain} is not available for registration", self::RESPONSE_CODE_DOMAIN_TAKEN);
        }

        $this->validateContacts($contacts);

        $this->purchasedDomains[] = $domain;

        return 'mock_' . md5($domain . time());
    }

    /**
     * Suggest domain names
     */
    public function suggest(
        array|string $query,
        array $tlds = [],
        ?int $limit = null,
        ?string $filterType = null,
        ?int $priceMax = null,
        ?int $priceMin = null,
    ): array {
        $query = \is_array($query) ? implode('-', $query) : $query;
        $tlds = $tlds === [] ? $this->supportedTlds : $tlds;
        $limit ??= 10;

        $suggestions = [];
        $count = 0;

        if ($filterType === null || $filterType === 'suggestion') {
            foreach ($tlds as $tld) {
                if ($count >= $limit) {
                    break;
                }

                $domain = $query . '.' . ltrim((string) $tld, '.');
                $suggestions[$domain] = [
                    'available' => $this->available([$domain])[$domain],
                    'price' => null,
                    'type' => 'suggestion',
                ];
                $count++;
            }
        }

        if (($filterType === null || $filterType === 'premium') && $count < $limit) {
            foreach ($this->premiumDomains as $domain => $price) {
                if ($count >= $limit) {
                    break;
                }

                if ($priceMin !== null && $price < $priceMin) {
                    continue;
                }
                if ($priceMax !== null && $price > $priceMax) {
                    continue;
                }

                $suggestions[$domain] = [
                    'available' => $this->available([$domain])[$domain],
                    'price' => $price,
                    'type' => 'premium',
                ];
                $count++;
            }
        }

        return $suggestions;
    }

    /**
     * Get list of supported TLDs
     */
    public function tlds(): array
    {
        return $this->supportedTlds;
    }

    /**
     * Get domain information
     *
     * @throws DomainsException
     */
    public function getDomain(string $domain): Domain
    {
        if (!\in_array($domain, $this->purchasedDomains)) {
            throw new DomainsException("Domain {$domain} not found in mock registry", self::RESPONSE_CODE_NOT_FOUND);
        }

        return new Domain(
            domain: $domain,
            createdAt: new DateTime(),
            expiresAt: new DateTime('+1 year'),
            autoRenew: false,
            nameservers: [
                'ns1.example.com',
                'ns2.example.com',
            ],
        );
    }

    /**
     * Get the price for a domain
     *
     * @param int $ttl Time to live for the cache (if set) in seconds
     * @throws PriceNotFoundException
     */
    public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price
    {
        if ($this->cache instanceof \Utopia\Domains\Cache) {
            $cached = $this->cache->load($domain, $ttl);
            if (\is_array($cached) && isset($cached['price'])) {
                return new Price($cached['price'], $cached['premium'] ?? false);
            }
        }

        $isPremium = isset($this->premiumDomains[$domain]);

        if ($isPremium) {
            $price = $this->premiumDomains[$domain] * $periodYears;
            $result = new Price($price, true);
            if ($this->cache instanceof \Utopia\Domains\Cache) {
                $this->cache->save($domain, ['price' => $result->price, 'premium' => $result->premium]);
            }

            return $result;
        }

        $parts = explode('.', $domain);
        if (\count($parts) < 2) {
            throw new PriceNotFoundException("Invalid domain format: {$domain}", self::RESPONSE_CODE_BAD_REQUEST);
        }

        $tld = end($parts);

        if (!\in_array($tld, $this->supportedTlds)) {
            throw new PriceNotFoundException("TLD .{$tld} is not supported", self::RESPONSE_CODE_BAD_REQUEST);
        }

        $basePrice = $this->defaultPrice;
        $multiplier = match ($regType) {
            Registrar::REG_TYPE_TRANSFER => 1.0,
            Registrar::REG_TYPE_RENEWAL => 1.1,
            Registrar::REG_TYPE_TRADE => 1.2,
            default => 1.0,
        };

        $price = $basePrice * $periodYears * $multiplier;
        $result = new Price($price, false);
        if ($this->cache instanceof \Utopia\Domains\Cache) {
            $this->cache->save($domain, ['price' => $result->price, 'premium' => $result->premium]);
        }

        return $result;
    }

    /**
     * Renewal a domain
     *
     * @throws DomainsException
     */
    public function renew(string $domain, int $periodYears): Renewal
    {
        if (!\in_array($domain, $this->purchasedDomains)) {
            throw new DomainsException("Domain {$domain} not found in mock registry", self::RESPONSE_CODE_NOT_FOUND);
        }

        $domainInfo = $this->getDomain($domain);
        $currentExpiry = $domainInfo->expiresAt;
        $newExpiry = $currentExpiry instanceof \DateTime ? (clone $currentExpiry)->modify("+{$periodYears} years") : new DateTime("+{$periodYears} years");

        return new Renewal(
            orderId: 'mock_order_' . md5($domain . time()),
            expiresAt: $newExpiry,
        );
    }

    /**
     * Update domain information
     *
     * @throws DomainsException
     */
    public function updateDomain(string $domain, UpdateDetails $details): bool
    {
        if (!\in_array($domain, $this->purchasedDomains)) {
            throw new DomainsException("Domain {$domain} not found in mock registry", self::RESPONSE_CODE_NOT_FOUND);
        }

        if ($details->autoRenew === null) {
            throw new DomainsException('Details must include autoRenew', 400);
        }

        return true;
    }

    /**
     * Transfer a domain
     *
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     * @throws DomainTakenException
     */
    public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string
    {
        if (\in_array($domain, $this->purchasedDomains)) {
            throw new DomainTakenException("Domain {$domain} is already in this account", self::RESPONSE_CODE_DOMAIN_TAKEN);
        }

        $this->transferredDomains[] = $domain;
        $this->purchasedDomains[] = $domain;

        return 'mock_transfer_' . md5($domain . time());
    }

    /**
     * Get list of purchased domains (for testing purposes)
     */
    public function getPurchasedDomains(): array
    {
        return $this->purchasedDomains;
    }

    /**
     * Get list of transferred domains (for testing purposes)
     */
    public function getTransferredDomains(): array
    {
        return $this->transferredDomains;
    }

    /**
     * Reset the mock state (for testing purposes)
     */
    public function reset(): void
    {
        $this->purchasedDomains = [];
        $this->transferredDomains = [];
    }

    /**
     * Add a domain to the taken list (for testing purposes)
     */
    public function addTakenDomain(string $domain): void
    {
        if (!\in_array($domain, $this->takenDomains)) {
            $this->takenDomains[] = $domain;
        }
    }

    /**
     * Add a premium domain (for testing purposes)
     */
    public function addPremiumDomain(string $domain, float $price): void
    {
        $this->premiumDomains[$domain] = $price;
    }

    /**
     * Get the authorization code for an EPP domain
     *
     * @throws DomainsException
     */
    public function getAuthCode(string $domain): string
    {
        if (!\in_array($domain, $this->purchasedDomains)) {
            throw new DomainsException("Domain {$domain} not found in mock registry", self::RESPONSE_CODE_NOT_FOUND);
        }

        return 'mock_' . substr(md5($domain), 0, 8);
    }

    /**
     * Check transfer status for a domain
     */
    public function checkTransferStatus(string $domain): TransferStatus
    {
        if (\in_array($domain, $this->transferredDomains)) {
            return new TransferStatus(
                status: TransferStatusEnum::PendingRegistry,
                reason: 'Transfer in progress',
                timestamp: new DateTime(),
            );
        }
        if (\in_array($domain, $this->purchasedDomains)) {
            return new TransferStatus(
                status: TransferStatusEnum::Completed,
                reason: 'Domain already exists in mock account',
                timestamp: new DateTime(),
            );
        }
        return new TransferStatus(
            status: TransferStatusEnum::Transferrable,
        );

    }

    /**
     * Update the nameservers for a domain
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        return [
            'successful' => true,
            'nameservers' => $nameservers,
        ];
    }

    /**
     * Cancel pending purchase orders
     */
    public function cancelPurchase(): bool
    {
        return true;
    }

    /**
     * Validate contacts
     *
     * @throws InvalidContactException
     */
    private function validateContacts(array|Contact $contacts): void
    {
        $contactsArray = \is_array($contacts) ? $contacts : [$contacts];

        foreach ($contactsArray as $contact) {
            if (!($contact instanceof Contact)) {
                throw new InvalidContactException('Invalid contact: contact must be an instance of Contact', self::RESPONSE_CODE_INVALID_CONTACT);
            }

            $contactData = $contact->toArray();
            $required = [
                'firstname',
                'lastname',
                'email',
                'phone',
                'address1',
                'city',
                'state',
                'postalcode',
                'country',
            ];

            foreach ($required as $field) {
                if (!isset($contactData[$field]) || empty($contactData[$field])) {
                    throw new InvalidContactException("Invalid contact: missing required field '{$field}'", self::RESPONSE_CODE_INVALID_CONTACT);
                }
            }

            if (!filter_var($contactData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidContactException('Invalid contact: invalid email format', self::RESPONSE_CODE_INVALID_CONTACT);
            }
        }
    }
}
