<?php

namespace Utopia\Domains\Registrar\Adapter;

use DateTime;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Domains\Exception as DomainsException;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Domain;
use Utopia\Domains\Registrar\Exception\AuthException;
use Utopia\Domains\Registrar\Exception\DomainNotFoundException;
use Utopia\Domains\Registrar\Exception\DomainNotTransferableException;
use Utopia\Domains\Registrar\Exception\DomainTakenException;
use Utopia\Domains\Registrar\Exception\InvalidAuthCodeException;
use Utopia\Domains\Registrar\Exception\InvalidContactException;
use Utopia\Domains\Registrar\Exception\InvalidPeriodException;
use Utopia\Domains\Registrar\Exception\PriceNotFoundException;
use Utopia\Domains\Registrar\Exception\RateLimitException;
use Utopia\Domains\Registrar\Exception\UnsupportedTldException;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\TransferStatusEnum;
use Utopia\Domains\Registrar\UpdateDetails;
use Utopia\Psr7\Request\Factory;

class NameCom extends Adapter
{
    private const int AVAILABILITY_BATCH_SIZE = 50;

    private const int AVAILABILITY_CACHE_TTL = 60;

    private const int TLD_PAGE_SIZE = 1000;

    /**
     * TLDs with a minimum term above one year; availability quotes them at that term.
     */
    private const array MINIMUM_TERM_YEARS = ['ai' => 2];

    /**
     * Name.com API Error Keys
     */
    public const string ERROR_NOT_FOUND = 'Not Found';
    public const string ERROR_DOMAIN_TAKEN = 'Domain is not available';
    public const string ERROR_DOMAIN_DOES_NOT_EXIST = 'The requested domain does not exist.';
    public const string ERROR_INVALID_AUTH_CODE = 'we were unable to get authoritative domain information from the registry. this usually means that the domain name or auth code provided was not correct.';
    public const string ERROR_INVALID_CONTACT = 'invalid value for';
    public const string ERROR_INVALID_DOMAIN = 'Invalid Domain Name';
    public const string ERROR_INVALID_DOMAINS = 'None of the submitted domains are valid';
    public const string ERROR_INVALID_YEARS = 'Invalid value for years';
    public const string ERROR_UNSUPPORTED_TLD = 'unsupported tld';
    public const string ERROR_TLD_NOT_SUPPORTED = 'TLD not supported';
    public const string ERROR_UNSUPPORTED_TRANSFER = 'do not support transfers for';
    public const string ERROR_UNAUTHORIZED = 'Unauthorized';
    public const string ERROR_RATE_LIMIT_EXCEEDED = 'Rate Limit Exceeded';

    /**
     * Name.com API Error Map: [message => code]
     */
    public const array ERROR_MAP = [
        self::ERROR_NOT_FOUND => 404,
        self::ERROR_DOMAIN_TAKEN => null,
        self::ERROR_DOMAIN_DOES_NOT_EXIST => 404,
        self::ERROR_INVALID_AUTH_CODE => null,
        self::ERROR_INVALID_YEARS => 400,
        self::ERROR_INVALID_CONTACT => null,
        self::ERROR_INVALID_DOMAIN => null,
        self::ERROR_INVALID_DOMAINS => null,
        self::ERROR_UNSUPPORTED_TLD => 422,
        self::ERROR_TLD_NOT_SUPPORTED => null,
        self::ERROR_UNSUPPORTED_TRANSFER => 400,
        self::ERROR_UNAUTHORIZED => 401,
        self::ERROR_RATE_LIMIT_EXCEEDED => 429,
    ];

    /**
     * Contact Types
     */
    public const string CONTACT_TYPE_REGISTRANT = 'registrant';
    public const string CONTACT_TYPE_ADMIN = 'admin';
    public const string CONTACT_TYPE_TECH = 'tech';
    public const string CONTACT_TYPE_BILLING = 'billing';
    public const string CONTACT_TYPE_OWNER = 'owner';

    /**
     * __construct
     * Instantiate a new adapter.
     *
     * @param  string  $username  Name.com API username
     * @param  string  $token  Name.com API token
     * @param  string  $endpoint  The endpoint to use for the API (use https://api.name.com for production)
     * @param  ClientInterface|null  $client  Optional transport; owns timeout, TLS, redirect and connection settings
     */
    public function __construct(
        protected string $username,
        protected string $token,
        protected string $endpoint = 'https://api.name.com',
        ?ClientInterface $client = null,
    ) {
        $this->client = $client;

        if (str_starts_with($endpoint, 'http://')) {
            $this->endpoint = 'https://' . substr($endpoint, 7);
        } elseif (!str_starts_with($endpoint, 'https://')) {
            $this->endpoint = "https://{$endpoint}";
        }

        $this->headers = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get the name of this adapter
     */
    public function getName(): string
    {
        return 'namecom';
    }

    /**
     * Check if domains are available
     *
     * Name.com accepts up to 50 domains per availability request. Each result
     * also carries the registration and renewal price, so when a cache is set
     * the prices are stored too and a following getPrice() for the same domain
     * costs no registrar request.
     *
     * @param array<string> $domains Domain names to check
     * @return array<string, bool> Availability keyed by domain name
     */
    public function available(array $domains): array
    {
        $domains = array_values(array_unique($domains));
        $availability = array_fill_keys($domains, false);

        foreach (array_chunk($domains, self::AVAILABILITY_BATCH_SIZE) as $chunk) {
            try {
                $result = $this->send('POST', '/core/v1/domains:checkAvailability', [
                    'domainNames' => $chunk,
                ]);
            } catch (Exception $e) {
                if ($this->matchError($e) === self::ERROR_INVALID_DOMAINS) {
                    continue;
                }

                throw $e;
            }

            foreach ($result['results'] ?? [] as $domain) {
                $domainName = $domain['domainName'] ?? null;
                if ($domainName === null) {
                    continue;
                }
                if (!\array_key_exists((string) $domainName, $availability)) {
                    continue;
                }

                $availability[$domainName] = $domain['purchasable'] ?? false;

                if (!$this->cache instanceof \Utopia\Domains\Cache) {
                    continue;
                }

                $this->cache->save("{$domainName}_availability", $domain);
                if (empty($domain['purchasable'])) {
                    continue;
                }
                if (!isset($domain['purchasePrice'])) {
                    continue;
                }

                // Same premium rule as getPrice(). Only the price types this
                // endpoint reports are stored, so a later lookup for another
                // type still asks the registrar instead of being told the
                // price does not exist. A renewal price of 0 means name.com has
                // no renewal data for the listing.
                $purchaseType = $domain['purchaseType'] ?? 'registration';
                $isPremium = ($domain['premium'] ?? false) === true || ($purchaseType !== '' && $purchaseType !== 'registration');
                $cacheData = [
                    Registrar::REG_TYPE_NEW => ['price' => (float) $domain['purchasePrice'], 'premium' => $isPremium],
                ];
                if (!empty($domain['renewalPrice'])) {
                    $cacheData[Registrar::REG_TYPE_RENEWAL] = ['price' => (float) $domain['renewalPrice'], 'premium' => $isPremium];
                }

                $parts = explode('.', $domainName);
                $periodYears = self::MINIMUM_TERM_YEARS[end($parts)] ?? 1;
                $this->cache->save("{$domainName}_{$periodYears}", $cacheData);
            }
        }

        return $availability;
    }

    /**
     * Update nameservers for a domain
     *
     * @param string $domain The domain name
     * @param array $nameservers Array of nameserver hostnames
     * @return array Result with 'successful' boolean
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        try {
            $result = $this->send('POST', "/core/v1/domains/{$domain}:setNameservers", [
                'nameservers' => $nameservers,
            ]);

            return [
                'successful' => true,
                'nameservers' => $result['nameservers'] ?? $nameservers,
            ];
        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            return [
                'successful' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Purchase a new domain
     *
     * @param string $domain The domain name to purchase
     * @param array|Contact $contacts Contact information
     * @param int $periodYears Registration period in years
     * @param array $nameservers Nameservers to use
     * @param bool $autorenewEnabled Whether autorenew should be enabled
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string
    {
        try {
            $contacts = \is_array($contacts) ? $contacts : [$contacts];
            $nameservers = $nameservers === [] ? $this->defaultNameservers : $nameservers;

            $contactData = $this->sanitizeContacts($contacts);

            $data = [
                'domain' => [
                    'domainName' => $domain,
                    'nameservers' => $nameservers,
                    'contacts' => $contactData,
                    'autorenewEnabled' => $autorenewEnabled,
                ],
                'years' => $periodYears,
            ];

            if ($purchasePrice !== null) {
                $data['purchasePrice'] = $purchasePrice;
            }

            $result = $this->send('POST', '/core/v1/domains', $data);
            return (string) ($result['order'] ?? '');

        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            $message = 'Failed to purchase domain: ' . $e->getMessage();
            $code = $e->getCode();

            switch ($this->matchError($e)) {
                case self::ERROR_UNAUTHORIZED:
                    throw new AuthException($message, $code, $e);

                case self::ERROR_DOMAIN_TAKEN:
                    throw new DomainTakenException($message, $code, $e);

                case self::ERROR_INVALID_CONTACT:
                    throw new InvalidContactException($message, $code, $e);

                case self::ERROR_UNSUPPORTED_TLD:
                case self::ERROR_UNSUPPORTED_TRANSFER:
                    throw new UnsupportedTldException($message, $code, $e);

                default:
                    throw new DomainsException($message, $code, $e);
            }
        }
    }

    /**
     * Transfer a domain to this registrar
     *
     * @param string $domain The domain name to transfer
     * @param string $authCode Authorization code for the transfer
     * @param float|null $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string
    {
        try {
            $data = [
                'domainName' => $domain,
                'authCode' => $authCode,
            ];

            if ($purchasePrice !== null) {
                $data['purchasePrice'] = $purchasePrice;
            }

            $result = $this->send('POST', '/core/v1/transfers', $data);
            return (string) ($result['order'] ?? '');

        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            $message = 'Failed to transfer domain: ' . $e->getMessage();
            $code = $e->getCode();

            switch ($this->matchError($e)) {
                case self::ERROR_UNAUTHORIZED:
                    throw new AuthException($message, $code, $e);

                case self::ERROR_UNSUPPORTED_TLD:
                case self::ERROR_UNSUPPORTED_TRANSFER:
                    throw new UnsupportedTldException($message, $code, $e);

                case self::ERROR_INVALID_AUTH_CODE:
                    throw new InvalidAuthCodeException($message, $code, $e);

                case self::ERROR_DOMAIN_DOES_NOT_EXIST:
                    throw new DomainNotTransferableException($message, $code, $e);

                case self::ERROR_DOMAIN_TAKEN:
                    throw new DomainTakenException($message, $code, $e);

                default:
                    throw new DomainsException($message, $code, $e);
            }
        }
    }

    /**
     * Cancel pending purchase orders (Name.com doesn't have a direct equivalent)
     *
     * @return bool Always returns true as Name.com handles this differently
     */
    public function cancelPurchase(): bool
    {
        // Name.com doesn't have a direct equivalent to OpenSRS's cancel pending orders
        // Transfers can be cancelled individually using the CancelTransfer endpoint
        return true;
    }

    /**
     * Suggest domain names based on search query
     *
     * @param array|string $query Search terms to generate suggestions from
     * @param array $tlds Top-level domains to search within
     * @param int|null $limit Maximum number of results to return
     * @param string|null $filterType Filter results by type (not fully supported by Name.com API)
     * @param int|null $priceMax Maximum price for premium domains
     * @param int|null $priceMin Minimum price for premium domains
     * @return array Domains with metadata
     */
    public function suggest(array|string $query, array $tlds = [], ?int $limit = null, ?string $filterType = null, ?int $priceMax = null, ?int $priceMin = null): array
    {
        $query = \is_array($query) ? implode(' ', $query) : $query;

        $data = [
            'keyword' => $query,
        ];

        if ($tlds !== []) {
            $data['tldFilter'] = array_map(fn($tld): string => ltrim((string) $tld, '.'), $tlds);
        }

        if ($limit) {
            $data['limit'] = $limit;
        }

        $result = $this->send('POST', '/core/v1/domains:search', $data);

        $items = [];

        if (isset($result['results']) && \is_array($result['results'])) {
            foreach ($result['results'] as $domainResult) {
                $domain = $domainResult['domainName'] ?? null;
                if (!$domain) {
                    continue;
                }

                $purchasable = $domainResult['purchasable'] ?? false;
                $price = isset($domainResult['purchasePrice']) ? (float) $domainResult['purchasePrice'] : null;
                $renewalPrice = isset($domainResult['renewalPrice']) ? (float) $domainResult['renewalPrice'] : null;
                $purchaseType = $domainResult['purchaseType'] ?? 'registration';

                // Aftermarket listings (purchaseType other than 'registration')
                // are premium even when the premium flag is not set
                $isPremium = (isset($domainResult['premium']) && $domainResult['premium'] === true)
                    || ($purchaseType !== '' && $purchaseType !== 'registration');

                // Apply price filters
                if ($price !== null) {
                    if ($priceMin !== null && $price < $priceMin) {
                        continue;
                    }
                    if ($priceMax !== null && $price > $priceMax) {
                        continue;
                    }
                }

                // Apply filter type
                if ($filterType === 'premium' && !$isPremium) {
                    continue;
                }
                if ($filterType === 'suggestion' && $isPremium) {
                    continue;
                }

                $items[$domain] = [
                    'available' => $purchasable,
                    'price' => $price,
                    'renewalPrice' => $renewalPrice,
                    'purchaseType' => $purchaseType,
                    'type' => $isPremium ? 'premium' : 'suggestion',
                ];

                if ($limit && \count($items) >= $limit) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Get the registration price for a domain
     *
     * @param string $domain The domain name to get pricing for
     * @param int $periodYears Registration period in years
     * @param string $regType Type of registration
     * @param int $ttl Time to live for the cache
     * @return Price The price and premium status of the domain
     */
    public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price
    {
        $cacheKey = "{$domain}_{$periodYears}";

        if ($this->cache instanceof \Utopia\Domains\Cache) {
            $cached = $this->cache->load($cacheKey, $ttl);
            if (\is_array($cached[$regType] ?? null)) {
                if (($cached[$regType]['price'] ?? null) === null) {
                    throw new PriceNotFoundException("Price not found for domain: {$domain}", 400);
                }
                return new Price($cached[$regType]['price'], $cached[$regType]['premium']);
            }
        }

        try {
            $result = $this->send('GET', "/core/v1/domains/{$domain}:getPricing?years={$periodYears}");
            $isPremium = !empty($result['premium']);

            $priceMap = [
                Registrar::REG_TYPE_NEW      => $result['purchasePrice'] ?? null,
                Registrar::REG_TYPE_RENEWAL   => $result['renewalPrice'] ?? null,
                Registrar::REG_TYPE_TRANSFER  => $result['transferPrice'] ?? null,
            ];

            // getPricing only covers standard registry registrations. Premium
            // aftermarket listings are priced by the availability endpoint, so
            // without this merge a premium domain is quoted at the base TLD price.
            // A recent available() call leaves its result in the cache, which
            // saves one registrar request per domain in bulk price lookups.
            $availability = null;
            $availabilityFailed = false;
            if ($this->cache instanceof \Utopia\Domains\Cache) {
                $cachedAvailability = $this->cache->load("{$domain}_availability", self::AVAILABILITY_CACHE_TTL);
                if (\is_array($cachedAvailability)) {
                    $availability = $cachedAvailability;
                }
            }

            if ($availability === null) {
                try {
                    $availabilityResult = $this->send('POST', '/core/v1/domains:checkAvailability', [
                        'domainNames' => [$domain],
                    ]);
                    $availability = $availabilityResult['results'][0] ?? null;
                } catch (RateLimitException $e) {
                    throw $e;
                } catch (Exception) {
                    // Registry pricing is still usable for standard domains; skip
                    // the premium override and skip caching so the merge is
                    // retried on the next request
                    $availabilityFailed = true;
                }
            }

            $purchaseType = $availability['purchaseType'] ?? 'registration';
            if (
                !empty($availability['purchasable'])
                && (($availability['premium'] ?? false) === true || ($purchaseType !== '' && $purchaseType !== 'registration'))
            ) {
                $isPremium = true;
                if (isset($availability['purchasePrice'])) {
                    $priceMap[Registrar::REG_TYPE_NEW] = (float) $availability['purchasePrice'];
                }
                // A renewal price of 0 means name.com has no renewal data for
                // the listing, so keep the registry renewal price in that case
                if (!empty($availability['renewalPrice'])) {
                    $priceMap[Registrar::REG_TYPE_RENEWAL] = (float) $availability['renewalPrice'];
                }
            }

            if (!array_filter($priceMap, fn($p): bool => $p !== null)) {
                throw new PriceNotFoundException("Price not found for domain: {$domain}", 400);
            }

            if ($this->cache && !$availabilityFailed) {
                $cacheData = array_map(
                    fn($price): array => ['price' => $price !== null ? (float) $price : null, 'premium' => $isPremium],
                    $priceMap,
                );
                $this->cache->save($cacheKey, $cacheData);
            }

            $price = $priceMap[$regType] ?? null;
            if ($price === null) {
                throw new PriceNotFoundException("Price not found for domain: {$domain}", 400);
            }

            return new Price((float) $price, $isPremium);

        } catch (PriceNotFoundException|RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            $message = "Failed to get price for domain: {$domain} - " . $e->getMessage();
            $code = $e->getCode();
            $error = $this->matchError($e);

            switch ($error) {
                case self::ERROR_UNSUPPORTED_TLD:
                case self::ERROR_TLD_NOT_SUPPORTED:
                    throw new UnsupportedTldException($message, $code, $e);

                case self::ERROR_NOT_FOUND:
                case self::ERROR_INVALID_DOMAIN:
                    throw new PriceNotFoundException($message, $code, $e);

                case self::ERROR_INVALID_YEARS:
                    throw new InvalidPeriodException($message, $code, $e);

                default:
                    throw new DomainsException($message, $code, $e);
            }
        }
    }

    /**
     * Get list of available TLDs
     *
     * @return array List of TLD strings
     */
    public function tlds(): array
    {
        $tlds = [];
        $page = 1;

        do {
            $result = $this->send('GET', '/core/v1/tldpricing?perPage=' . self::TLD_PAGE_SIZE . "&page={$page}");
            foreach ($result['pricing'] ?? [] as $pricing) {
                if (isset($pricing['tld'])) {
                    $tlds[] = (string) $pricing['tld'];
                }
            }
            $page = $result['nextPage'] ?? null;
        } while ($page !== null);

        return $tlds;
    }

    /**
     * Get domain information
     *
     * @param string $domain The domain name
     * @return Domain Domain information
     */
    public function getDomain(string $domain): Domain
    {
        try {
            $result = $this->send('GET', "/core/v1/domains/{$domain}");

            $createdAt = isset($result['createDate']) ? new DateTime($result['createDate']) : null;
            $expiresAt = isset($result['expireDate']) ? new DateTime($result['expireDate']) : null;
            $autoRenew = isset($result['autorenewEnabled']) && (bool) $result['autorenewEnabled'];
            $nameservers = $result['nameservers'] ?? [];

            return new Domain(
                domain: $domain,
                createdAt: $createdAt,
                expiresAt: $expiresAt,
                autoRenew: $autoRenew,
                nameservers: $nameservers,
            );
        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DomainsException('Failed to get domain information: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Update domain information
     *
     * Example request:
     * <code>
     * $details = new UpdateDetails(
     *     autoRenew: true
     * );
     * $reg->updateDomain('example.com', $details);
     * </code>
     *
     * @see https://docs.name.com/docs/api-reference/domains/update-a-domain
     *
     * @param string $domain The domain name to update
     * @param UpdateDetails $details The details to update
     * @return bool True if successful
     */
    public function updateDomain(string $domain, UpdateDetails $details): bool
    {
        if ($details->autoRenew === null) {
            throw new DomainsException('Details must include autoRenew', 400);
        }

        try {
            $this->send('PATCH', "/core/v1/domains/{$domain}", [
                'autorenewEnabled' => $details->autoRenew,
            ]);
            return true;
        } catch (RateLimitException|DomainsException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DomainsException('Failed to update domain: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Renew a domain
     *
     * @see https://docs.name.com/docs/api-reference/domains/renew-domain#renew-domain
     *
     * @param string $domain The domain name to renew
     * @param int $periodYears The number of years to renew
     * @return Renewal Renewal information
     */
    public function renew(string $domain, int $periodYears): Renewal
    {
        try {
            $data = [
                'years' => $periodYears,
            ];

            $result = $this->send('POST', "/core/v1/domains/{$domain}:renew", $data);

            $orderId = (string) ($result['order'] ?? '');
            $expiresAt = isset($result['domain']['expireDate']) ? new DateTime($result['domain']['expireDate']) : null;

            return new Renewal(
                orderId: $orderId,
                expiresAt: $expiresAt,
            );
        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DomainsException('Failed to renew domain: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get the authorization code for an EPP domain
     *
     * @see https://docs.name.com/docs/api-reference/domains/get-auth-code-for-domain#get-auth-code-for-domain
     *
     * @param string $domain The domain name
     * @return string The authorization code
     */
    public function getAuthCode(string $domain): string
    {
        try {
            $result = $this->send('GET', "/core/v1/domains/{$domain}:getAuthCode");

            if (isset($result['authCode'])) {
                return $result['authCode'];
            }

            throw new DomainsException('Auth code not found in response', 404);
        } catch (RateLimitException|DomainsException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DomainsException('Failed to get auth code: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check transfer status for a domain
     *
     * @param string $domain The domain name
     * @return TransferStatus Transfer status information
     */
    public function checkTransferStatus(string $domain): TransferStatus
    {
        try {
            $result = $this->send('GET', "/core/v1/transfers/{$domain}");

            $status = $this->mapTransferStatus($result['status'] ?? 'unknown');
            $reason = $result['statusDetails'] ?? null;

            return new TransferStatus(
                status: $status,
                reason: $reason,
                timestamp: isset($result['created']) ? new DateTime($result['created']) : null,
            );
        } catch (RateLimitException $e) {
            throw $e;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                throw new DomainNotFoundException("Domain not found: {$domain}", $e->getCode(), $e);
            }

            throw new DomainsException('Failed to check transfer status: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Map Name.com transfer status to TransferStatusEnum
     *
     * Name.com statuses: canceled, canceled_pending_refund, completed, failed,
     * pending, pending_insert, pending_new_auth_code, pending_transfer,
     * pending_unlock, rejected, submitting_transfer
     *
     * @see https://docs.name.com/docs/api-reference/transfers/get-transfer#get-transfer
     *
     * @param string $status Name.com status string
     */
    private function mapTransferStatus(string $status): TransferStatusEnum
    {
        return match (strtolower($status)) {
            'completed' => TransferStatusEnum::Completed,
            'canceled', 'canceled_pending_refund', 'rejected' => TransferStatusEnum::Cancelled,
            'pending', 'pending_transfer', 'submitting_transfer' => TransferStatusEnum::PendingRegistry,
            'pending_insert' => TransferStatusEnum::PendingAdmin,
            'pending_new_auth_code', 'pending_unlock' => TransferStatusEnum::PendingOwner,
            'failed' => TransferStatusEnum::NotTransferrable,
            default => TransferStatusEnum::NotTransferrable,
        };
    }

    /**
     * Send an API request to Name.com
     *
     * @param string $method HTTP method
     * @param string $path API endpoint path
     * @param array|null $data Request data
     * @return array Response data
     */
    private function send(string $method, string $path, ?array $data = null): array
    {
        $url = "{$this->endpoint}{$path}";

        $body = '';

        if ($data !== null && \in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                $jsonError = json_last_error_msg();
                throw new Exception("Failed to encode request data to JSON: {$jsonError}");
            }

            $body = $jsonData;
        }

        $request = new Factory()->body($method, $url, $body, 'application/json', $this->headers)
            ->withHeader('Authorization', 'Basic ' . base64_encode("{$this->username}:{$this->token}"));

        try {
            $httpResponse = $this->getHttpClient()->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new Exception("Failed to send request to Name.com: {$error->getMessage()}", 0, $error);
        }

        $httpCode = $httpResponse->getStatusCode();
        $result = (string) $httpResponse->getBody();

        $response = json_decode($result, true);
        if ($response === null && $result !== 'null' && $result !== '') {
            throw new Exception('Failed to parse response from Name.com: Invalid JSON');
        }

        if ($httpCode >= 400) {
            $message = $response['message'] ?? 'Unknown error';
            $details = $response['details'] ?? null;

            if ($details) {
                $message .= "({$details})";
            }

            if ($httpCode === 429 || stripos((string) $message, self::ERROR_RATE_LIMIT_EXCEEDED) !== false) {
                throw new RateLimitException("Rate limit exceeded: {$message}", 429);
            }

            throw new Exception($message, $httpCode);
        }

        return $response ?? [];
    }

    /**
     * Match an exception against the error map.
     * Returns the matched error key, or null if no match is found.
     *
     * @param Exception $e The exception to check
     * @return string|null The matched error key from ERROR_MAP, or null
     */
    private function matchError(Exception $e): ?string
    {
        $errorLower = strtolower($e->getMessage());
        $code = $e->getCode();

        foreach (self::ERROR_MAP as $message => $expectedCode) {
            if ($expectedCode !== null && $code !== $expectedCode) {
                continue;
            }

            if (str_contains($errorLower, strtolower($message))) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Sanitize contacts array to Name.com format
     *
     * @param array<mixed> $contacts Contact objects keyed by role or position
     * @return array Sanitized contacts in Name.com format
     */
    private function sanitizeContacts(array $contacts): array
    {
        if ($contacts === []) {
            throw new InvalidContactException('Contacts must be a non-empty array', 400);
        }

        // Validate all items are Contact instances
        foreach ($contacts as $key => $contact) {
            if (!$contact instanceof Contact) {
                $keyInfo = \is_int($key) ? "index $key" : "key '$key'";
                throw new InvalidContactException("Contact at $keyInfo must be an instance of Contact", 400);
            }
        }

        // Use first contact as default fallback
        $defaultContact = reset($contacts);

        // Map contacts to required types using null coalescing
        // Checks associative keys first, then numeric indices, then falls back to default
        $mappings = [
            self::CONTACT_TYPE_REGISTRANT => $contacts[self::CONTACT_TYPE_REGISTRANT]
                ?? $contacts[self::CONTACT_TYPE_OWNER]
                ?? $contacts[0]
                ?? $defaultContact,
            self::CONTACT_TYPE_ADMIN => $contacts[self::CONTACT_TYPE_ADMIN]
                ?? $contacts[1]
                ?? $defaultContact,
            self::CONTACT_TYPE_TECH => $contacts[self::CONTACT_TYPE_TECH]
                ?? $contacts[2]
                ?? $defaultContact,
            self::CONTACT_TYPE_BILLING => $contacts[self::CONTACT_TYPE_BILLING]
                ?? $contacts[3]
                ?? $defaultContact,
        ];

        // Format all contacts
        $result = [];
        foreach ($mappings as $type => $contact) {
            $result[$type] = $this->formatContact($contact);
        }

        return $result;
    }

    /**
     * Format a Contact object to Name.com API format
     *
     * @param Contact $contact Contact object
     * @return array Formatted contact data
     */
    private function formatContact(Contact $contact): array
    {
        $data = $contact->toArray();

        return [
            'firstName' => $data['firstname'] ?? '',
            'lastName' => $data['lastname'] ?? '',
            'companyName' => $data['org'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'address1' => $data['address1'] ?? '',
            'address2' => $data['address2'] ?? '',
            'city' => $data['city'] ?? '',
            'state' => $data['state'] ?? '',
            'zip' => $data['postalcode'] ?? '',
            'country' => $data['country'] ?? '',
        ];
    }
}
