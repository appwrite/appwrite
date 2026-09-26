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
use Utopia\Domains\Registrar\Exception\DomainNotTransferableException;
use Utopia\Domains\Registrar\Exception\DomainTakenException;
use Utopia\Domains\Registrar\Exception\InvalidContactException;
use Utopia\Domains\Registrar\Exception\PriceNotFoundException;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\TransferStatusEnum;
use Utopia\Domains\Registrar\UpdateDetails;
use Utopia\Psr7\Request\Factory;

class OpenSRS extends Adapter
{
    /**
     * OpenSRS API Response Codes - https://domains.opensrs.guide/docs/codes
     */
    public const RESPONSE_CODE_DOMAIN_AVAILABLE = 210;
    public const RESPONSE_CODE_NOTHING_TO_DO = 220;
    public const RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND = 400;
    public const RESPONSE_CODE_INVALID_CONTACT = 465;
    public const RESPONSE_CODE_DOMAIN_TAKEN = 485;
    public const RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE = 487;

    /**
     * Contact Types
     */
    public const CONTACT_TYPE_OWNER = 'owner';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_BILLING = 'billing';

    protected array $user;

    public function getName(): string
    {
        return 'opensrs';
    }

    /**
     * __construct
     * Instantiate a new adapter.
     *
     * @param  string  $endpoint - The endpoint to use for the API (use rr-n1-tor.opensrs.net:55443 for production)
     * @param  ClientInterface|null  $client  Optional transport; owns timeout, TLS, redirect and connection settings
     */
    public function __construct(
        protected string $apiKey,
        string $username,
        string $password,
        protected string $endpoint = 'https://horizon.opensrs.net:55443',
        ?ClientInterface $client = null,
    ) {
        $this->client = $client;

        if (str_starts_with($endpoint, 'http://')) {
            $this->endpoint = 'https://' . substr($endpoint, 7);
        } elseif (!str_starts_with($endpoint, 'https://')) {
            $this->endpoint = 'https://' . $endpoint;
        }

        $this->user = [
            'username' => $username,
            'password' => $password,
        ];

        $this->headers = [
            'Content-Type' => 'text/xml',
            'X-Username' => $username,
        ];
    }

    /**
     * Check if domains are available
     *
     * @param array<string> $domains Domain names to check
     * @return array<string, bool> Availability keyed by domain name
     */
    public function available(array $domains): array
    {
        $availability = [];

        foreach (array_unique($domains) as $domain) {
            $result = $this->send([
                'object' => 'DOMAIN',
                'action' => 'LOOKUP',
                'attributes' => [
                    'domain' => $domain,
                ],
            ]);

            $result = $this->sanitizeResponse($result);
            $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="response_code"]');
            $availability[$domain] = (int) $elements[0] === self::RESPONSE_CODE_DOMAIN_AVAILABLE;
        }

        return $availability;
    }

    public function updateNameservers(string $domain, array $nameservers): array
    {
        $message = [
            'object' => 'DOMAIN',
            'action' => 'ADVANCED_UPDATE_NAMESERVERS',
            'domain' => $domain,
            'attributes' => [
                'add_ns' => $nameservers,
                'op_type' => 'add_remove',
            ],
        ];

        $result = $this->send($message);
        $result = $this->sanitizeResponse($result);

        $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="is_success"]');
        $successful = "{$elements[0]}" === '1';

        $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="response_text"]');
        $text = "{$elements[0]}";

        $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="response_code"]');
        $code = "{$elements[0]}";

        return [
            'code' => $code,
            'text' => $text,
            'successful' => $successful,
            'nameservers' => $nameservers,
        ];
    }

    private function register(string $domain, string $regType, array $user, array $contacts, array $nameservers = [], int $periodYears = 1, ?string $authCode = null, ?float $purchasePrice = null, bool $autorenewEnabled = false): string
    {
        $hasNameservers = $nameservers === [] ? 0 : 1;

        $message = [
            'object' => 'DOMAIN',
            'action' => 'SW_REGISTER',
            'attributes' => [
                'domain' => $domain,
                'periodYears' => $periodYears,
                'contact_set' => $contacts,
                'custom_tech_contact' => 0,
                'custom_nameservers' => $hasNameservers,
                'reg_username' => $user['username'],
                'reg_password' => $user['password'],
                'reg_type' => $regType,
                'handle' => 'process',
                'f_whois_privacy' => 1,
                'auto_renew' => $autorenewEnabled ? 1 : 0,
            ],
        ];

        if ($authCode) {
            $message['attributes']['auth_info'] = $authCode;
        }

        if ($hasNameservers !== 0) {
            $message['attributes']['nameserver_list'] = $nameservers;
        }

        if ($purchasePrice !== null) {
            $message['attributes']['premium_price_to_display'] = $purchasePrice;
        }

        return $this->send($message);
    }

    public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string
    {
        try {
            $contacts = \is_array($contacts) ? $contacts : [$contacts];

            $nameservers
            = $nameservers === []
            ? $this->defaultNameservers
            : $nameservers;

            $contacts = $this->sanitizeContacts($contacts);

            $regType = Registrar::REG_TYPE_NEW;

            $result = $this->register($domain, $regType, $this->user, $contacts, $nameservers, $periodYears, null, $purchasePrice, $autorenewEnabled);
            $result = $this->response($result);
            return $result['id'];

        } catch (Exception $e) {
            $message = 'Failed to purchase domain: ' . $e->getMessage();

            if ($e->getCode() === self::RESPONSE_CODE_DOMAIN_TAKEN) {
                throw new DomainTakenException($message, $e->getCode(), $e);
            }
            if ($e->getCode() === self::RESPONSE_CODE_INVALID_CONTACT && str_contains($e->getMessage(), 'Invalid data')) {
                throw new InvalidContactException($message, $e->getCode(), $e);
            }
            if ($e->getCode() === self::RESPONSE_CODE_INVALID_CONTACT && str_contains($e->getMessage(), 'password')) {
                throw new AuthException($message, $e->getCode(), $e);
            }
            throw new DomainsException($message, $e->getCode(), $e);
        }
    }

    public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string
    {
        $regType = Registrar::REG_TYPE_TRANSFER;

        try {
            $result = $this->register($domain, $regType, $this->user, [], [], 1, $authCode, $purchasePrice);
            $result = $this->response($result);
            return $result['id'];

        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === self::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE) {
                $parts = explode("\n", $e->getMessage());
                $reason = $parts[1] ?? $parts[0];
                throw new DomainNotTransferableException('Domain is not transferable: ' . $reason, $e->getCode(), $e);
            }
            if ($code === self::RESPONSE_CODE_DOMAIN_TAKEN) {
                throw new DomainTakenException('Domain is already in this account', $code, $e);
            }
            throw new DomainsException('Failed to transfer domain: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function cancelPurchase(): bool
    {
        $timestamp = date('Y-m-d\TH:i:s.000');
        $timestamp = strtotime($timestamp);

        $message = [
            'object' => 'ORDER',
            'action' => 'CANCEL_PENDING_ORDERS',
            'attributes' => [
                'to_date' => $timestamp,
                'status' => [
                    'declined',
                    'pending',
                ],
            ],
        ];

        $result = $this->send($message);
        $result = $this->sanitizeResponse($result);

        $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="is_success"]');

        return "{$elements[0]}" === '1';
    }

    /**
     * Suggest domain names based on search query
     *
     * @param array|string $query Search terms to generate suggestions from
     * @param array $tlds Top-level domains to search within (e.g., ['com', 'net', 'org'])
     * @param int|null $limit Maximum number of results to return
     * @param string|null $filterType Filter results by type: 'premium', 'suggestion', or null for both
     * @param int|null $priceMax Maximum price for premium domains
     * @param int|null $priceMin Minimum price for premium domains
     * @return array Domains with metadata: `available` (bool), `price` (float|null), `type` (string)
     */
    public function suggest(array|string $query, array $tlds = [], ?int $limit = null, ?string $filterType = null, ?int $priceMax = null, ?int $priceMin = null): array
    {
        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            throw new Exception("Invalid price range: priceMin ($priceMin) must be less than priceMax ($priceMax).");
        }

        if ($filterType !== null && !\in_array($filterType, ['premium', 'suggestion'])) {
            throw new Exception("Invalid filter type: filterType ($filterType) must be 'premium' or 'suggestion'.");
        }

        if ($filterType !== null && $filterType === 'suggestion' && ($priceMin !== null || $priceMax !== null)) {
            throw new Exception("Invalid price range: priceMin ($priceMin) and priceMax ($priceMax) cannot be set when filterType is 'suggestion'.");
        }

        $query = \is_array($query) ? $query : [$query];
        $message = [
            'object' => 'DOMAIN',
            'action' => 'NAME_SUGGEST',
            'attributes' => [
                'services' => ['suggestion', 'premium', 'lookup'],
                'searchstring' => implode(' ', $query),
                'skip_registry_lookup' => 1,
            ],
        ];
        $tlds = $tlds === [] ? [] : array_map(fn($tld): string => '.' . ltrim((string) $tld, '.'), $tlds);

        if ($tlds !== []) {
            $message['attributes']['tlds'] = $tlds;
            if ($filterType === 'premium' || $filterType === null) {
                $message['attributes']['service_override']['premium']['tlds'] = $tlds;
            }
            if ($filterType === 'suggestion' || $filterType === null) {
                $message['attributes']['service_override']['suggestion']['tlds'] = $tlds;
            }
            $message['attributes']['service_override']['lookup']['tlds'] = $tlds;
        }
        if ($limit) {
            if ($filterType === 'premium' || $filterType === null) {
                $message['attributes']['service_override']['premium']['maximum'] = $limit;
            }
            if ($filterType === 'suggestion' || $filterType === null) {
                $message['attributes']['service_override']['suggestion']['maximum'] = $limit;
            }
        }
        if ($priceMin !== null) {
            $message['attributes']['service_override']['premium']['price_min'] = $priceMin;
        }
        if ($priceMax !== null) {
            $message['attributes']['service_override']['premium']['price_max'] = $priceMax;
        }

        $result = $this->send($message);
        $result = $this->sanitizeResponse($result);

        $items = [];

        // Process suggestion domains
        if ($filterType === 'suggestion' || $filterType === null) {
            $suggestionXpath = implode('/', [
                '//body',
                'data_block',
                'dt_assoc',
                'item[@key="attributes"]',
                'dt_assoc',
                'item[@key="suggestion"]',
                'dt_assoc',
                'item[@key="items"]',
                'dt_array',
                'item',
            ]);
            $suggestionElements = $result->xpath($suggestionXpath);

            $processedCount = 0;
            $suggestionLimit = $limit;

            foreach ($suggestionElements as $element) {
                if ($suggestionLimit !== null && $processedCount >= $suggestionLimit) {
                    break;
                }

                $domainNode = $element->xpath('dt_assoc/item[@key="domain"]');
                $statusNode = $element->xpath('dt_assoc/item[@key="status"] | dt_assoc/item[@key="availability"]');
                $domain = isset($domainNode[0]) ? (string) $domainNode[0] : null;
                $status = isset($statusNode[0]) ? strtolower((string) $statusNode[0]) : '';
                $available = \in_array($status, ['available', 'true', '1'], true);

                if ($domain) {
                    $items[$domain] = [
                        'available' => $available,
                        'price' => null,
                        'type' => 'suggestion',
                    ];

                    $processedCount++;
                }
            }

            if ($filterType === 'suggestion') {
                return $items;
            }

            if ($limit && \count($items) >= $limit) {
                return \array_slice($items, 0, $limit, true);
            }
        }

        // Process premium domains
        if (!$limit || \count($items) < $limit) {
            $premiumXpath = implode('/', [
                '//body',
                'data_block',
                'dt_assoc',
                'item[@key="attributes"]',
                'dt_assoc',
                'item[@key="premium"]',
                'dt_assoc',
                'item[@key="items"]',
                'dt_array',
                'item',
            ]);
            $premiumElements = $result->xpath($premiumXpath);

            $remainingLimit = $limit ? ($limit - \count($items)) : null;
            $processedCount = 0;

            foreach ($premiumElements as $element) {
                if ($remainingLimit !== null && $processedCount >= $remainingLimit) {
                    break;
                }

                $item = $element->xpath('dt_assoc/item');

                $domain = null;
                $available = false;
                $price = null;

                foreach ($item as $field) {
                    $key = (string) $field['key'];
                    $value = (string) $field;

                    switch ($key) {
                        case 'domain':
                            $domain = $value;
                            break;
                        case 'status':
                            $available = $value === 'available';
                            break;
                        case 'price':
                            $price = is_numeric($value) ? \floatval($value) : null;
                            break;
                    }
                }

                if ($domain) {
                    $items[$domain] = [
                        'available' => $available,
                        'price' => $price,
                        'type' => 'premium',
                    ];

                    $processedCount++;
                }
            }
        }

        return $items;
    }

    /**
     * Get the registration price for a domain
     *
     * @param string $domain The domain name to get pricing for
     * @param int $periodYears Registration periodYears in years (default 1)
     * @param string $regType Type of registration: 'new', 'renewal', 'transfer', or 'trade'
     * @param int $ttl Time to live for the cache (if set) in seconds (default 3600 seconds = 1 hour)
     * @return Price The price and premium status of the domain
     * @throws PriceNotFoundException When pricing information is not found or unavailable for the domain
     * @throws DomainsException When other errors occur during price retrieval
     */
    public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price
    {
        if ($this->cache instanceof \Utopia\Domains\Cache) {
            $cached = $this->cache->load($domain, $ttl);
            if (\is_array($cached) && isset($cached['price'])) {
                return new Price($cached['price'], $cached['premium'] ?? false);
            }
        }

        try {
            $message = [
                'object' => 'DOMAIN',
                'action' => 'GET_PRICE',
                'attributes' => [
                    'domain' => $domain,
                    'periodYears' => $periodYears,
                    'reg_type' => $regType,
                ],
            ];

            $result = $this->send($message);
            $result = $this->sanitizeResponse($result);

            $priceXpath = '//body/data_block/dt_assoc/item[@key="attributes"]/dt_assoc/item[@key="price"]';
            $priceElements = $result->xpath($priceXpath);
            $price = isset($priceElements[0]) ? \floatval((string) $priceElements[0]) : null;

            if ($price === null) {
                throw new PriceNotFoundException('Price not found for domain: ' . $domain, self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND);
            }

            $priceObj = new Price($price, false);
            if ($this->cache instanceof \Utopia\Domains\Cache) {
                $this->cache->save($domain, ['price' => $priceObj->price, 'premium' => $priceObj->premium]);
            }

            return $priceObj;
        } catch (Exception $e) {
            $message = 'Failed to get price for domain: ' . $e->getMessage();

            if ($e->getCode() === self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND) {
                throw new PriceNotFoundException($message, $e->getCode(), $e);
            }
            throw new DomainsException($message, $e->getCode(), $e);
        }
    }

    public function tlds(): array
    {
        // OpenSRS offers no endpoint for this
        return [];
    }

    public function getDomain(string $domain): Domain
    {
        $message = [
            'object' => 'DOMAIN',
            'action' => 'GET',
            'domain' => $domain,
            'attributes' => [
                'type' => 'all_info',
                'clean_ca_subset' => 1,
            ],
        ];

        $xpath = implode('/', [
            '//body',
            'data_block',
            'dt_assoc',
            'item[@key="attributes"]',
            'dt_assoc',
            'item',
        ]);

        $result = $this->send($message);
        $result = $this->sanitizeResponse($result);
        $elements = $result->xpath($xpath);

        $registryCreateDate = null;
        $registryExpireDate = null;
        $autoRenew = null;
        $nameserverList = null;

        foreach ($elements as $element) {
            $key = "{$element['key']}";
            $value = "{$element}";

            if ($key === 'registry_createdate') {
                $registryCreateDate = new DateTime($value);
            } elseif ($key === 'registry_expiredate') {
                $registryExpireDate = new DateTime($value);
            } elseif ($key === 'auto_renew') {
                $autoRenew = $value === '1';
            } elseif ($key === 'nameserver_list') {
                $nameserverList = [];
                $nameserverItems = $element->xpath('dt_array/item/dt_assoc');
                foreach ($nameserverItems as $nameserverItem) {
                    $nameItems = $nameserverItem->xpath('item[@key="name"]');
                    if (!empty($nameItems)) {
                        $nameserverName = trim((string) $nameItems[0]);
                        if ($nameserverName !== '' && $nameserverName !== '0') {
                            $nameserverList[] = $nameserverName;
                        }
                    }
                }
            }
        }

        return new Domain(
            domain: $domain,
            createdAt: $registryCreateDate,
            expiresAt: $registryExpireDate,
            autoRenew: $autoRenew,
            nameservers: $nameserverList,
        );
    }

    /**
     * Update the domain information
     *
     * Example request:
     * <code>
     * $details = new UpdateDetails(
     *     autoRenew: true
     * );
     * $reg->updateDomain('example.com', $details);
     * </code>
     *
     * @param string $domain The domain name to update
     * @param UpdateDetails $details The details to update the domain with
     * @return bool True if the domain was updated successfully, false otherwise
     */
    public function updateDomain(string $domain, UpdateDetails $details): bool
    {
        if ($details->autoRenew === null) {
            throw new DomainsException('Details must include autoRenew', 400);
        }

        $attributes = [
            'data' => 'expire_action',
            'affect_domains' => 0,
            'auto_renew' => $details->autoRenew ? 1 : 0,
            'let_expire' => $details->autoRenew ? 0 : 1,
        ];

        $message = [
            'object' => 'DOMAIN',
            'action' => 'MODIFY',
            'domain' => $domain,
            'attributes' => $attributes,
        ];

        $xpath = implode('/', [
            '//body',
            'data_block',
            'dt_assoc',
            'item[@key="attributes"]',
            'dt_assoc',
            'item[@key="details"]',
            'dt_assoc',
            'item[@key="' . $domain . '"]',
            'dt_assoc',
            'item[@key="is_success"]',
        ]);

        $result = $this->send($message);
        $result = $this->sanitizeResponse($result);

        $responseCode = $result->xpath('//body/data_block/dt_assoc/item[@key="response_code"]');
        if (!empty($responseCode) && (int) $responseCode[0] === self::RESPONSE_CODE_NOTHING_TO_DO) {
            return true;
        }

        $elements = $result->xpath($xpath);
        if (empty($elements)) {
            $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="is_success"]');
        }

        if (empty($elements)) {
            throw new DomainsException('Failed to update domain: invalid response from OpenSRS', 500);
        }

        return (string) $elements[0] === '1';
    }

    /**
     * Renewal a domain
     *
     * @param string $domain The domain name to renew
     * @param int $periodYears The number of years to renew the domain for
     * @return Renewal Contains the renewal information
     */
    public function renew(string $domain, int $periodYears): Renewal
    {
        $message = [
            'object' => 'DOMAIN',
            'action' => 'RENEW',
            'attributes' => [
                'domain' => $domain,
                'auto_renew' => 0,
                'currentexpirationyear' => '2022',
                'periodYears' => $periodYears,
                'handle' => 'process',
            ],
        ];

        $xpath = implode('/', [
            '//body',
            'data_block',
            'dt_assoc',
            'item[@key="attributes"]',
            'dt_assoc',
            'item',
        ]);

        $result = $this->send($message);
        $result = simplexml_load_string($result);
        $elements = $result->xpath($xpath);

        $orderId = null;
        $newExpiration = null;

        foreach ($elements as $item) {
            $key = "{$item['key']}";

            if ($key === 'registration expiration date') {
                $newExpiration = new DateTime("{$item}");
            } elseif ($key === 'order_id') {
                $orderId = "{$item}";
            }
        }

        return new Renewal(
            orderId: $orderId,
            expiresAt: $newExpiration,
        );
    }

    /**
     * Get the authorization code for an EPP domain
     *
     * @param string $domain The EPP domain name for which to retrieve the auth code
     * @return string The authorization code
     * @throws DomainsException When the domain does not use EPP protocol or other errors occur
     */
    public function getAuthCode(string $domain): string
    {
        try {
            $message = [
                'object' => 'DOMAIN',
                'action' => 'GET',
                'domain' => $domain,
                'attributes' => [
                    'type' => 'domain_auth_info',
                ],
            ];

            $result = $this->send($message);
            $result = $this->sanitizeResponse($result);

            $xpath = '//body/data_block/dt_assoc/item[@key="attributes"]/dt_assoc/item[@key="domain_auth_info"]';
            $elements = $result->xpath($xpath);

            if (empty($elements)) {
                throw new DomainsException('Auth code not found in response', 404);
            }

            return (string) $elements[0];
        } catch (Exception $e) {
            throw new DomainsException('Failed to get auth code: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check transfer status for a domain
     *
     * @param string $domain The fully qualified domain name
     * @return TransferStatus Contains transfer status information including 'status', 'reason', etc.
     * @throws DomainsException When errors occur during the check
     */
    public function checkTransferStatus(string $domain): TransferStatus
    {
        try {
            $message = [
                'object' => 'DOMAIN',
                'action' => 'CHECK_TRANSFER',
                'attributes' => [
                    'domain' => $domain,
                    'check_status' => 1, // Always check status
                    'get_request_address' => 0, // Never get request address
                ],
            ];

            $result = $this->send($message);
            $result = $this->sanitizeResponse($result);

            $xpath = '//body/data_block/dt_assoc/item[@key="attributes"]/dt_assoc/item';
            $elements = $result->xpath($xpath);

            $transferrable = 0;
            $noservice = 0;
            $reason = null;
            $statusStr = null;
            $timestamp = null;

            foreach ($elements as $element) {
                $key = (string) $element['key'];
                $value = (string) $element;

                switch ($key) {
                    case 'transferrable':
                        $transferrable = (int) $value;
                        break;
                    case 'noservice':
                        $noservice = (int) $value;
                        break;
                    case 'reason':
                        $reason = $value;
                        break;
                    case 'status':
                        $statusStr = $value;
                        break;
                    case 'timestamp':
                        $timestamp = new DateTime($value);
                        break;
                }
            }

            // Map OpenSRS response to TransferStatus enum
            $status = match (true) {
                $noservice === 1 => TransferStatusEnum::ServiceUnavailable,
                $transferrable === 1 => TransferStatusEnum::Transferrable,
                $statusStr === 'pending_owner' => TransferStatusEnum::PendingOwner,
                $statusStr === 'pending_admin' => TransferStatusEnum::PendingAdmin,
                $statusStr === 'pending_registry' => TransferStatusEnum::PendingRegistry,
                $statusStr === 'completed' => TransferStatusEnum::Completed,
                $statusStr === 'cancelled' => TransferStatusEnum::Cancelled,
                default => TransferStatusEnum::NotTransferrable,
            };

            return new TransferStatus(
                status: $status,
                reason: $reason,
                timestamp: $timestamp,
            );
        } catch (Exception $e) {
            throw new DomainsException('Failed to check transfer status: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    private function send(array $params = []): string
    {
        $object = $params['object'];
        $action = $params['action'];
        $domain = $params['domain'] ?? null;
        $attributes = $params['attributes'];

        $xml = $this->buildEnvelop($object, $action, $attributes, $domain);

        $headers = array_merge($this->headers, [
            'X-Signature' => md5(md5($xml . $this->apiKey) . $this->apiKey),
        ]);

        $request = new Factory()->xml('POST', $this->endpoint, $xml, $headers);

        try {
            $response = $this->getHttpClient()->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new Exception('Failed to send request to OpenSRS: ' . $error->getMessage(), 0, $error);
        }

        return (string) $response->getBody();
    }

    private function sanitizeResponse(string $response): \SimpleXMLElement
    {
        $result = simplexml_load_string($response);
        if ($result === false) {
            throw new Exception('OpenSRS returned an invalid XML response');
        }

        $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="response_code"]');
        $code = (int) "{$elements[0]}";

        if ($code > 299) {
            $elements = $result->xpath('//body/data_block/dt_assoc/item[@key="response_text"]');
            $text = "{$elements[0]}";

            throw new Exception($text, $code);
        }

        return $result;
    }

    private function response(string $xml): array
    {
        $doc = $this->sanitizeResponse($xml);
        $elements = $doc->xpath('//data_block/dt_assoc/item[@key="response_code"]');
        $responseCode = "{$elements[0]}";

        $elements = $doc->xpath('//data_block/dt_assoc/item[@key="attributes"]/dt_assoc/item[@key="id"]');
        $responseId = \count($elements) > 0 ? "{$elements[0]}" : '';

        $elements = $doc->xpath('//data_block/dt_assoc/item[@key="attributes"]/dt_assoc/item[@key="domain_id"]');
        $responseDomainId = \count($elements) > 0 ? "{$elements[0]}" : '';

        $elements = $doc->xpath('//data_block/dt_assoc/item[@key="is_success"]');
        $responseSuccessful = "{$elements[0]}" === '1';

        return [
            'code' => $responseCode,
            'id' => $responseId,
            'domainId' => $responseDomainId,
            'successful' => $responseSuccessful,
        ];
    }

    private function createArray(string $key, array $ary): string
    {
        $result = [
            '<item key="' . $key . '">',
            '<dt_array>',
        ];

        foreach ($ary as $key => $value) {
            $result[] = $this->createEnvelopItem($key, $value);
        }

        $result[] = '</dt_array>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createAssoc(string $key, array $assoc): string
    {
        $result = [
            '<item key="' . $key . '">',
            '<dt_assoc>',
        ];

        foreach ($assoc as $itemKey => $itemValue) {
            if (\is_array($itemValue)) {
                if (array_keys($itemValue) === range(0, \count($itemValue) - 1)) {
                    $result[] = $this->createArray($itemKey, $itemValue);
                } else {
                    $result[] = $this->createAssoc($itemKey, $itemValue);
                }
            } else {
                $result[] = $this->createEnvelopItem($itemKey, $itemValue);
            }
        }

        $result[] = '</dt_assoc>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createServiceOverride(array $overrides): string
    {
        $result = [
            '<item key="service_override">',
            '<dt_assoc>',
        ];

        foreach ($overrides as $serviceName => $serviceConfig) {
            $result[] = $this->createAssoc($serviceName, $serviceConfig);
        }

        $result[] = '</dt_assoc>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createEnvelopItem(string $key, string|int|array $value): string
    {
        if (\is_array($value)) {
            return $this->createArray($key, $value);
        }

        return "<item key='{$key}'>{$value}</item>";
    }

    private function validateContact(array $contact): array
    {
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
            'owner',
            'org',
        ];

        $filter = [
            'firstname' => 'first_name',
            'lastname' => 'last_name',
            'org' => 'org_name',
            'postalcode' => 'postal_code',
        ];

        $result = [];

        foreach ($required as $key) {
            $key = strtolower($key);

            if (! isset($contact[$key])) {
                throw new InvalidContactException("Contact is missing required field: {$key}");
            }

            $filtered_key = $filter[$key] ?? $key;

            $result[$filtered_key] = $contact[$key];
        }

        return $result;
    }

    private function createContact(string $type, array $contact): string
    {
        $contact = $this->validateContact($contact);
        $result = [
            "<item key='{$type}'>",
            '<dt_assoc>',
        ];

        foreach ($contact as $key => $value) {
            $result[] = $this->createEnvelopItem($key, $value);
        }

        $result[] = '</dt_assoc>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createContactSet(array $contacts): string
    {
        $result = [
            '<item key="contact_set">',
            '<dt_assoc>',
        ];

        foreach ($contacts as $type => $contact) {
            $result[] = $this->createContact($type, $contact);
        }

        $result[] = '</dt_assoc>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createNameserver(string $name, int $sortOrder): string
    {
        return implode(PHP_EOL, [
            '<dt_assoc>',
            $this->createEnvelopItem('name', $name),
            $this->createEnvelopItem('sortorder', $sortOrder),
            '</dt_assoc>',
        ]);
    }

    private function createNameserverList(array $nameservers): string
    {
        $result = [
            '<item key="nameserver_list">',
            '<dt_array>',
        ];
        $counter = \count($nameservers);

        for ($index = 0; $index < $counter; $index++) {
            $result[] = $this->createNameserver($nameservers[$index], $index);
        }

        $result[] = '</dt_array>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function createNamespaceAssign(array $nameservers): string
    {
        $result = [
            '<item key="add_ns">',
            '<dt_array>',
        ];
        $counter = \count($nameservers);

        for ($index = 0; $index < $counter; $index++) {
            $result[] = $this->createEnvelopItem((string) $index, $nameservers[$index]);
        }

        $result[] = '</dt_array>';
        $result[] = '</item>';

        return implode(PHP_EOL, $result);
    }

    private function buildEnvelop(string $object, string $action, array $attributes, ?string $domain = null): string
    {
        $result = [
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>',
            "<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>",
            '<OPS_envelope>',
            '<header>',
            '<version>0.9</version>',
            '</header>',
            '<body>',
            '<data_block>',
            '<dt_assoc>',
            $this->createEnvelopItem('protocol', 'XCP'),
            $this->createEnvelopItem('object', $object),
            $this->createEnvelopItem('action', $action),
            (
                \is_null($domain)
                ? ''
                : $this->createEnvelopItem('domain', $domain)
            ),
            '<item key="attributes">',
            '<dt_assoc>',
        ];

        foreach ($attributes as $key => $value) {
            $result[] = match ($key) {
                'contact_set' => $this->createContactSet($value),
                'nameserver_list' => $this->createNameserverList($value),
                'assign_ns', 'add_ns', 'remove_ns' => $this->createNamespaceAssign($value),
                'service_override' => $this->createServiceOverride($value),
                default => \is_array($value)
                ? $this->createArray($key, $value)
                : $this->createEnvelopItem($key, $value),
            };
        }

        $closing = [
            '</dt_assoc>',
            '</item>',
            '</dt_assoc>',
            '</data_block>',
            '</body>',
            '</OPS_envelope>',
        ];

        foreach ($closing as $line) {
            $result[] = $line;
        }

        return implode(PHP_EOL, $result);
    }

    /**
     * Sanitize the contacts
     *
     * @param Contact[] $contacts Array of Contact objects to sanitize
     * @return array The sanitized contacts
     */
    private function sanitizeContacts(array $contacts): array
    {
        if (\count(array_keys($contacts)) === 1) {
            return [
                self::CONTACT_TYPE_OWNER => $contacts[0]->toArray(),
                self::CONTACT_TYPE_ADMIN => $contacts[0]->toArray(),
                self::CONTACT_TYPE_TECH => $contacts[0]->toArray(),
                self::CONTACT_TYPE_BILLING => $contacts[0]->toArray(),
            ];
        }

        $result = [];
        foreach ($contacts as $key => $val) {
            $result[$key] = $val->toArray();
        }

        return $result;
    }
}
