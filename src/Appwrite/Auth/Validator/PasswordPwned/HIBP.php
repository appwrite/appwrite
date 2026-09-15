<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned;
use Utopia\Cache\Cache;
use Utopia\Fetch\Client;

/**
 * Asks the public Have I Been Pwned range API.
 *
 * Uses k-anonymity: only the first five characters of the password's SHA-1 hash
 * leave the server, and the candidate suffixes that come back are compared
 * locally. Range responses are cached per prefix, so a busy project does not
 * ask the service the same question twice.
 *
 * DSN: `hibp://localhost`, no details are read.
 */
class HIBP extends PasswordPwned
{
    public const ENDPOINT = 'https://api.pwnedpasswords.com/range';
    public const CACHE_TTL = 3600; // seconds

    private const PREFIX_LENGTH = 5;

    protected ?Cache $cache;
    protected Client $client;
    protected string $endpoint;

    public function __construct(?Cache $cache = null, ?Client $client = null, string $endpoint = self::ENDPOINT)
    {
        $this->cache = $cache;
        $this->client = $this->client($client);
        $this->endpoint = \rtrim($endpoint, '/');
    }

    protected function isPwned(string $password): bool
    {
        $hash = \strtoupper(\sha1($password));
        $prefix = \substr($hash, 0, self::PREFIX_LENGTH);
        $suffix = \substr($hash, self::PREFIX_LENGTH);

        // Entries with no breaches are dropped, so any hit means the password is breached
        return isset($this->range($prefix)[$suffix]);
    }

    /**
     * Breach counts for every known hash sharing the prefix, from cache or the service.
     *
     * @return array<string, int> hash suffix => breach count
     */
    private function range(string $prefix): array
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
            $this->unavailable();
        }

        if ($response->getStatusCode() !== 200) {
            $this->unavailable();
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
}
