<?php

declare(strict_types=1);

namespace Utopia\Schedule\Store;

use Redis as Client;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Store;

/**
 * Claim storage in Redis: one hash per scheduler, replaced through a Lua
 * compare-and-swap so leadership and coverage move together or not at
 * all.
 *
 * The comparison has to happen inside the script. A read followed by a
 * write lets two instances both pass the check and both believe they
 * lead, which quietly removes the guarantee the fencing rests on.
 *
 * The key carries no expiry, equally deliberately. A claim expires by
 * its own `expiresAt` field, which a successor reads to decide whether
 * it may take over; a TTL on the key would delete the watermark along
 * with the lease, and the next leader would start from "now" and skip
 * every occurrence in the gap.
 *
 * The key is used verbatim inside the script, so pass the full name
 * rather than relying on a client-side prefix.
 */
final readonly class Redis implements Store
{
    private const string SWAP = <<<'LUA'
        local held = redis.call('hget', KEYS[1], 'token')

        if ARGV[1] == '1' then
            if held then return 0 end
        elseif held ~= ARGV[2] then
            return 0
        end

        redis.call('hset', KEYS[1], 'token', ARGV[3], 'expiresAt', ARGV[4], 'coveredUntil', ARGV[5], 'syncedUntil', ARGV[6])

        return 1
        LUA;

    public function __construct(
        private Client $redis,
        private string $key = 'utopia-schedule-claim',
    ) {}

    #[\Override]
    public function load(): ?Claim
    {
        $record = $this->redis->hGetAll($this->key);

        if (!\is_array($record) || !isset($record['token'], $record['expiresAt'])) {
            return null;
        }

        // Redis hash fields are strings, so the record's moments are written
        // and read back at fixed precision rather than through float casts of
        // whatever the client hands over.
        $coveredUntil = (string) ($record['coveredUntil'] ?? '');
        $syncedUntil = (string) ($record['syncedUntil'] ?? '');

        return new Claim(
            $record['token'],
            (float) $record['expiresAt'],
            $coveredUntil === '' ? null : (float) $coveredUntil,
            $syncedUntil === '' ? null : (float) $syncedUntil,
        );
    }

    #[\Override]
    public function swap(?string $expected, Claim $next): bool
    {
        // An empty token is a released claim, so "no record at all" needs
        // its own flag rather than an empty expected value.
        $result = $this->redis->eval(self::SWAP, [
            $this->key,
            $expected === null ? '1' : '0',
            $expected ?? '',
            $next->token,
            \sprintf('%.6F', $next->expiresAt),
            $next->coveredUntil === null ? '' : \sprintf('%.6F', $next->coveredUntil),
            $next->syncedUntil === null ? '' : \sprintf('%.6F', $next->syncedUntil),
        ], 1);

        return \in_array($result, [1, '1'], true);
    }
}
