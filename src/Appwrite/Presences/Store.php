<?php

namespace Appwrite\Presences;

use Utopia\Cache\Cache;

/**
 * Tracks each user's live connections and their last-known presence in the cache.
 *
 * Each connection is its own cache field under a per-user key, so holds and releases are
 * independent writes (HSET/HDEL) and can never clobber another connection's entry. A field
 * carries its value and the time it was last seen; a presence message refreshes it, a clean
 * close removes it, and a field not refreshed within the keep-alive ages out. The user's
 * presence is the most recently seen live field; the user is offline once none remain.
 *
 * Ids are hex-encoded into keys and fields because the cache lowercases both, which would
 * otherwise collide case-variant ids and corrupt ids read back out.
 */
class Store
{
    public const int KEEP_ALIVE = 900;

    /** Queue of users whose row should be deleted off the hot path. */
    private const REAP_KEY = 'presence:reap';

    public function __construct(private readonly Cache $cache)
    {
    }

    private static function key(string $projectId, string $userId): string
    {
        return 'presence:holders:' . \bin2hex($projectId . "\0" . $userId);
    }

    private static function field(string $value): string
    {
        return \bin2hex($value);
    }

    /** Record or refresh a connection as a live holder; one atomic field write. */
    public function hold(string $projectId, string $userId, string $connectionId, mixed $value): void
    {
        $this->cache->save(self::key($projectId, $userId), ['seenAt' => \time(), 'value' => $value], self::field($connectionId));
    }

    /** Drop a holder and return how many live holders remain for the user. Idempotent. */
    public function release(string $projectId, string $userId, string $connectionId): int
    {
        $this->cache->purge(self::key($projectId, $userId), self::field($connectionId));

        return $this->count($projectId, $userId);
    }

    /**
     * Re-stamp the given live connections in one field write each, so a worker's periodic
     * flush keeps still-connected holders from ageing out.
     *
     * @param  array<int, string>  $connectionIds
     */
    public function refresh(string $projectId, string $userId, array $connectionIds): void
    {
        $key = self::key($projectId, $userId);
        foreach ($connectionIds as $connectionId) {
            $field = self::field($connectionId);
            $holder = $this->cache->load($key, self::KEEP_ALIVE, $field);
            if (\is_array($holder)) {
                $holder['seenAt'] = \time();
                $this->cache->save($key, $holder, $field);
            }
        }
    }

    /** Drop every holder for a user, e.g. when the presence row is deleted out of band. */
    public function clear(string $projectId, string $userId): void
    {
        $this->cache->purge(self::key($projectId, $userId));
    }

    /** Live holders for the user, pruning any past their keep-alive as it counts. */
    public function count(string $projectId, string $userId): int
    {
        $key = self::key($projectId, $userId);

        $live = 0;
        foreach ($this->cache->list($key) as $field) {
            if ($this->cache->load($key, self::KEEP_ALIVE, $field) !== null) {
                $live++;
            } else {
                $this->cache->purge($key, $field);
            }
        }

        return $live;
    }

    public function isPresent(string $projectId, string $userId): bool
    {
        return $this->count($projectId, $userId) > 0;
    }

    /** The most recently seen live holder's value, or null when the user is offline. */
    public function current(string $projectId, string $userId): mixed
    {
        $key = self::key($projectId, $userId);

        $latest = null;
        foreach ($this->cache->list($key) as $field) {
            $holder = $this->cache->load($key, self::KEEP_ALIVE, $field);
            if (!\is_array($holder)) {
                continue;
            }
            if ($latest === null || ($holder['seenAt'] ?? 0) > ($latest['seenAt'] ?? 0)) {
                $latest = $holder;
            }
        }

        return $latest['value'] ?? null;
    }

    /** Queue a user whose last holder just left, so the reaper deletes its row off the hot path. */
    public function markForReap(string $projectId, string $userId): void
    {
        $this->cache->save(self::REAP_KEY, ['projectId' => $projectId, 'userId' => $userId], self::field($projectId . "\0" . $userId));
    }

    /**
     * Users awaiting row deletion, their last holder gone.
     *
     * @return array<int, array{projectId: string, userId: string}>
     */
    public function reapCandidates(): array
    {
        $candidates = [];
        foreach ($this->cache->list(self::REAP_KEY) as $field) {
            $entry = $this->cache->load(self::REAP_KEY, self::KEEP_ALIVE, $field);
            if (\is_array($entry) && isset($entry['projectId'], $entry['userId'])) {
                $candidates[] = ['projectId' => (string) $entry['projectId'], 'userId' => (string) $entry['userId']];
            }
        }

        return $candidates;
    }

    /** Remove a user from the reap queue. */
    public function clearReap(string $projectId, string $userId): void
    {
        $this->cache->purge(self::REAP_KEY, self::field($projectId . "\0" . $userId));
    }
}
