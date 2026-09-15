<?php

namespace Appwrite\Auth\Validator;

use Utopia\Fetch\Client;

/**
 * Validates that a password has not been exposed in a known data breach.
 *
 * Uses the Have I Been Pwned range API with k-anonymity: only the first five
 * characters of the SHA-1 hash leave the server, and the returned candidate
 * suffixes are compared locally. When the breach service cannot be reached the
 * check is skipped, so an outage of the third party never blocks sign-ups or
 * password changes.
 */
class PasswordPwned extends Password
{
    public const ENDPOINT = 'https://api.pwnedpasswords.com/range';

    private const PREFIX_LENGTH = 5;
    private const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    protected string $endpoint;
    protected Client $client;

    public function __construct(?string $endpoint = null, ?Client $client = null, bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);
        $this->endpoint = \rtrim($endpoint ?: self::ENDPOINT, '/');
        $this->client = $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Password must not have been exposed in a known data breach.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        $hash = \strtoupper(\sha1($value));
        $prefix = \substr($hash, 0, self::PREFIX_LENGTH);
        $suffix = \substr($hash, self::PREFIX_LENGTH);

        try {
            $response = $this->client
                ->addHeader('Add-Padding', 'true')
                ->fetch($this->endpoint . '/' . $prefix);
        } catch (\Throwable) {
            return true;
        }

        if ($response->getStatusCode() !== 200) {
            return true;
        }

        // Each line is `HASH_SUFFIX:COUNT`; padded entries carry a count of 0 and are not breaches
        foreach (\explode("\n", $response->text()) as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }

            $separator = \strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $candidate = \strtoupper(\substr($line, 0, $separator));
            $count = (int) \substr($line, $separator + 1);

            if ($candidate === $suffix && $count > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
