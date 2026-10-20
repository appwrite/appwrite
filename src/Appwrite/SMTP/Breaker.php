<?php

namespace Appwrite\SMTP;

use Utopia\Cache\Cache;
use Utopia\Span\Span;

/**
 * Stops dialling a project's own SMTP host after repeated refused logins from our shared egress IPs.
 * Counts need a cache that keeps fields under a key (Redis); any other keeps the breaker closed.
 */
final class Breaker
{
    public const int THRESHOLD = 5;

    public const int WINDOW = 600;

    public const int COOLDOWN = 900;

    /**
     * Replies that refuse the login itself (RFC 4954).
     */
    public const array CODES = [530, 534, 535];

    private readonly string $key;

    private readonly int $now;

    public function __construct(private readonly Cache $cache, string $project, string $host, ?int $now = null)
    {
        $this->key = 'smtp-breaker:' . $project . ':' . \md5(\strtolower(\trim($host)));
        $this->now = $now ?? \time();
    }

    public function isOpen(): bool
    {
        return (int) $this->load($this->key . ':open', self::COOLDOWN) > $this->now;
    }

    /**
     * Counts a refusal with a login reply code; true when it opens the breaker.
     */
    public function record(int $code): bool
    {
        if (!\in_array($code, self::CODES, true)) {
            return false;
        }

        // One field per refusal in a fixed window, so concurrent workers never overwrite each other's count.
        $failures = $this->key . ':failures:' . \intdiv($this->now, self::WINDOW);

        try {
            $this->cache->save($failures, (string) $this->now, \bin2hex(\random_bytes(8)), self::WINDOW);
            $open = \count($this->cache->list($failures)) >= self::THRESHOLD;

            if ($open) {
                $this->cache->save($this->key . ':open', (string) ($this->now + self::COOLDOWN), ttl: self::COOLDOWN);
            }
        } catch (\Throwable $th) {
            Span::add('smtp.breaker.error', $th->getMessage());

            return false;
        }

        return $open;
    }

    private function load(string $key, int $ttl): mixed
    {
        try {
            return $this->cache->load($key, $ttl);
        } catch (\Throwable $th) {
            Span::add('smtp.breaker.error', $th->getMessage());

            return false;
        }
    }
}
