<?php

namespace Appwrite\Presences;

use Utopia\Cache\Cache;

/**
 * Tracks each user's live connections and their last-known presence in the cache.
 *
 * One cache entry per user holds a map of connectionId => [seenAt, value]. A presence
 * message refreshes an entry, a clean close drops it, and an entry not refreshed within
 * the keep-alive ages out. The user's presence is the most recently seen live entry;
 * the user is offline once no live entries remain.
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
        return 'presence:holders:' . $projectId . ':' . $userId;
    }

    /**
     * The user's live holders, stale entries dropped.
     *
     * @return array<string, array{seenAt: int, value: mixed}>
     */
    public function holders(string $projectId, string $userId): array
    {
        $holders = $this->cache->load(self::key($projectId, $userId), self::KEEP_ALIVE);
        if (!\is_array($holders)) {
            return [];
        }

        $cutoff = \time() - self::KEEP_ALIVE;

        return \array_filter($holders, static fn ($holder): bool => \is_array($holder) && ($holder['seenAt'] ?? 0) > $cutoff);
    }

    /** Record or refresh a connection as a live holder, carrying its latest presence value. */
    public function hold(string $projectId, string $userId, string $connectionId, mixed $value): void
    {
        $holders = $this->holders($projectId, $userId);
        $holders[$connectionId] = ['seenAt' => \time(), 'value' => $value];

        $this->cache->save(self::key($projectId, $userId), $holders);
    }

    /** Drop a holder and return how many live holders remain for the user. */
    public function release(string $projectId, string $userId, string $connectionId): int
    {
        $holders = $this->holders($projectId, $userId);
        unset($holders[$connectionId]);

        $key = self::key($projectId, $userId);
        if ($holders === []) {
            $this->cache->purge($key);
        } else {
            $this->cache->save($key, $holders);
        }

        return \count($holders);
    }

    /**
     * Refresh the deadline of the given live connections for a user in one write, so a
     * worker's periodic flush keeps still-connected holders from ageing out.
     *
     * @param  array<int, string>  $connectionIds
     */
    public function refresh(string $projectId, string $userId, array $connectionIds): void
    {
        $holders = $this->holders($projectId, $userId);

        $now = \time();
        $changed = false;
        foreach ($connectionIds as $connectionId) {
            if (isset($holders[$connectionId])) {
                $holders[$connectionId]['seenAt'] = $now;
                $changed = true;
            }
        }

        if ($changed) {
            $this->cache->save(self::key($projectId, $userId), $holders);
        }
    }

    /** Drop every holder for a user, e.g. when the presence row is deleted out of band. */
    public function clear(string $projectId, string $userId): void
    {
        $this->cache->purge(self::key($projectId, $userId));
    }

    /** Queue a user whose last holder just left, so the reaper deletes its row off the hot path. */
    public function markForReap(string $projectId, string $userId): void
    {
        $this->cache->save(self::REAP_KEY, (string) \time(), $projectId . ':' . $userId);
    }

    /**
     * Users awaiting row deletion, their last holder gone.
     *
     * @return array<int, array{projectId: string, userId: string}>
     */
    public function reapCandidates(): array
    {
        $candidates = [];
        foreach ($this->cache->list(self::REAP_KEY) as $member) {
            [$projectId, $userId] = \explode(':', (string) $member, 2) + ['', ''];
            if ($userId === '') {
                continue;
            }
            $candidates[] = ['projectId' => $projectId, 'userId' => $userId];
        }

        return $candidates;
    }

    /** Remove a user from the reap queue. */
    public function clearReap(string $projectId, string $userId): void
    {
        $this->cache->purge(self::REAP_KEY, $projectId . ':' . $userId);
    }

    public function count(string $projectId, string $userId): int
    {
        return \count($this->holders($projectId, $userId));
    }

    public function isPresent(string $projectId, string $userId): bool
    {
        return $this->holders($projectId, $userId) !== [];
    }

    /** The most recently seen live holder's value, or null when the user is offline. */
    public function current(string $projectId, string $userId): mixed
    {
        $holders = $this->holders($projectId, $userId);
        if ($holders === []) {
            return null;
        }

        \uasort($holders, static fn ($a, $b): int => ($b['seenAt'] ?? 0) <=> ($a['seenAt'] ?? 0));

        return \reset($holders)['value'] ?? null;
    }
}
