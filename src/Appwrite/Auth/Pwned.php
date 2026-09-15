<?php

namespace Appwrite\Auth;

use Appwrite\Extend\Exception;
use Utopia\Fetch\Client;

/**
 * Looks a password up in a database of known data breaches.
 *
 * The adapter and its details both come from `_APP_PWNED_PASSWORDS_DSN`, whose
 * scheme picks the adapter. Adapters differ in what leaves the server and where
 * it goes, so read the concrete class before choosing one.
 */
abstract class Pwned
{
    private const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    /**
     * Adapter name, matching the DSN scheme that selects it.
     */
    abstract public function getName(): string;

    /**
     * Whether the password appears in a known data breach.
     *
     * @throws Exception when the breach service cannot be reached or answers with nonsense
     */
    abstract public function isPwned(string $password): bool;

    /**
     * A client with the timeouts a password check can afford to wait.
     */
    protected function client(?Client $client = null): Client
    {
        return $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

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
