<?php

namespace Appwrite\Auth\Validator;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Fetch\Client;

/**
 * Validates that a password has not been exposed in a known data breach.
 *
 * Configured from the project's `password-pwned` policy. Uses the Have I Been
 * Pwned range API with k-anonymity: only the first five characters of the
 * SHA-1 hash leave the server, and the returned suffixes are compared locally.
 * Range responses are cached per prefix so repeated checks do not hit the
 * service again.
 */
class PasswordPwned extends Password
{
    public const ENDPOINT = 'https://api.pwnedpasswords.com/range';
    public const CACHE_TTL = 3600; // seconds

    private const PREFIX_LENGTH = 5;
    private const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    protected bool $enabled;
    protected string $endpoint;
    protected bool $sessions;
    protected bool $users;
    protected ?Cache $cache;
    protected Client $client;

    /**
     * @param array<string, mixed> $policy the project's `passwordPwned` auth settings
     * @param ?string $endpoint the breach API this server is configured to use
     */
    public function __construct(array $policy = [], ?Cache $cache = null, ?string $endpoint = null, ?Client $client = null, bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);

        $this->enabled = (bool) ($policy['enabled'] ?? true);
        $this->sessions = (bool) ($policy['sessions'] ?? false);
        $this->users = (bool) ($policy['users'] ?? false);
        $this->endpoint = \rtrim($endpoint ?: self::ENDPOINT, '/');
        $this->cache = $cache;
        $this->client = $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

    /**
     * Whether the project enforces the policy at all.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether passwords are checked when a session is created.
     */
    public function checksSessions(): bool
    {
        return $this->enabled && $this->sessions;
    }

    /**
     * Whether users signing in with a breached password are blocked until they reset it.
     *
     * Only takes effect when sessions are checked.
     */
    public function blocksUsers(): bool
    {
        return $this->users;
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
     * Whether the password appears in a known breach.
     *
     * Returns null only when the policy is disabled.
     *
     * @throws Exception when the breach service cannot be reached
     */
    public function check(string $password): ?bool
    {
        if (!$this->enabled) {
            return null;
        }

        $hash = \strtoupper(\sha1($password));
        $prefix = \substr($hash, 0, self::PREFIX_LENGTH);
        $suffix = \substr($hash, self::PREFIX_LENGTH);

        $breaches = $this->range($prefix);

        if ($breaches === null) {
            throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
        }

        // Entries with no breaches are dropped, so any hit means the password is breached
        return isset($breaches[$suffix]);
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     * @throws Exception when the breach service is unreachable and the policy fails closed
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        return $this->check($value) !== true;
    }

    /**
     * Breach counts for every known hash sharing the prefix, from cache or the service.
     *
     * @return ?array<string, int> hash suffix => breach count, null when the service could not be reached
     */
    private function range(string $prefix): ?array
    {
        $key = 'pwned-passwords:' . \md5($this->endpoint) . ':' . $prefix;

        if ($this->cache !== null) {
            $cached = $this->cache->load($key, self::CACHE_TTL);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        try {
            $response = $this->client
                ->addHeader('Add-Padding', 'true')
                ->fetch($this->endpoint . '/' . $prefix);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        // Each line is `HASH_SUFFIX:COUNT`; padded entries carry a count of 0 and are not breaches
        $breaches = [];
        foreach (\explode("\n", $response->text()) as $line) {
            $line = \trim($line);
            $separator = \strpos($line, ':');
            if ($line === '' || $separator === false) {
                continue;
            }

            $count = (int) \substr($line, $separator + 1);
            if ($count > 0) {
                $breaches[\strtoupper(\substr($line, 0, $separator))] = $count;
            }
        }

        $this->cache?->save($key, $breaches);

        return $breaches;
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
