<?php

namespace Appwrite\Auth;

use Appwrite\Extend\Exception;
use Utopia\Fetch\Client;

/**
 * Looks a password up in a database of known data breaches.
 *
 * Adapters differ in what leaves the server and where it goes, so read the
 * concrete class before choosing one. Pick the adapter with
 * `_APP_PWNED_PASSWORDS_ADAPTER` and the address with
 * `_APP_PWNED_PASSWORDS_ENDPOINT`.
 */
abstract class Pwned
{
    protected const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    protected const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    protected string $endpoint;
    protected Client $client;

    public function __construct(string $endpoint, ?Client $client = null)
    {
        $this->endpoint = \rtrim($endpoint, '/');
        $this->client = $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

    /**
     * Adapter name, as accepted by `_APP_PWNED_PASSWORDS_ADAPTER`.
     */
    abstract public function getName(): string;

    /**
     * Whether the password appears in a known data breach.
     *
     * @throws Exception when the breach service cannot be reached or answers with nonsense
     */
    abstract public function isPwned(string $password): bool;

    /**
     * A password is never silently accepted when the lookup did not happen.
     *
     * @throws Exception
     */
    protected function unavailable(): never
    {
        throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
    }
}
