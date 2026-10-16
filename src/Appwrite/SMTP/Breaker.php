<?php

namespace Appwrite\SMTP;

use Utopia\Cache\Cache;
use Utopia\Span\Span;

/**
 * Stops dialling a project's own SMTP host after repeated refused logins from our shared egress IPs.
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

        $failures = $this->load($this->key . ':failures', self::WINDOW);
        $failures = \array_values(\array_filter(
            \is_array($failures) ? $failures : [],
            fn (mixed $at): bool => (int) $at > $this->now - self::WINDOW,
        ));
        $failures[] = $this->now;

        // The open key is written apart from the tally so a stale tally write cannot close it.
        $open = \count($failures) >= self::THRESHOLD;

        try {
            $this->cache->save($this->key . ':failures', $failures, ttl: self::WINDOW);

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
