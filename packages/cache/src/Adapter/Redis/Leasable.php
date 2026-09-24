<?php

namespace Utopia\Cache\Adapter\Redis;

use Throwable;
use Utopia\Cache\Codec;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\Envelope;

/**
 * Shared generation-lease + purge-tombstone behaviour for the Redis-protocol
 * cache adapters (Redis and Redis\Multiplexing). Subclasses supply the transport
 * by implementing leaseEvalSha()/leaseEval()/leaseHget(); everything lease-related
 * — the reserved fields, the three atomic single-key Lua scripts, the grace
 * window, getGeneration(), saveWithLease() and purge() — lives here so it can't
 * drift between adapters.
 *
 * Scripts are dispatched through leaseRun(): EVALSHA of the (locally computed)
 * body digest, falling back to EVAL once on NoScript so the server re-caches it.
 * This keeps the hot path a ~40-byte frame instead of the full script body every
 * call, which matters most on the multiplexed connection where a fat frame
 * head-of-line-blocks every other operation queued behind it.
 *
 * Implements the transport-agnostic \Utopia\Cache\Feature\Leasable capability,
 * referenced by FQN to avoid shadowing this class's own short name.
 */
abstract class Leasable implements \Utopia\Cache\Feature\Leasable
{
    /**
     * Reserved hash field that holds a key's generation alongside its value
     * fields. Keeping the generation inside the value's own hash makes every
     * lease operation single-key (one shard): no sidecar key to collide with or
     * scan, and the scripts stay correct under Redis Cluster / multi-threaded
     * backends (e.g. Dragonfly), where a multi-key script would span shards.
     * Callers must not use this as a cache hash/field.
     */
    protected const GENERATION_FIELD = '__utopia_gen__';

    /**
     * Reserved hash field: epoch-µs deadline until which saveWithLease() is
     * refused after a purge, so a reader whose DB read lagged the write can't
     * re-cache stale data under a still-valid generation token. Not a cache field.
     */
    protected const TOMBSTONE_FIELD = '__utopia_tomb__';

    /**
     * Save $hash only if the generation token matches and the key is past its
     * post-purge tombstone. Single-key. KEYS[1]=key; ARGV[1]=hash, ARGV[2]=value,
     * ARGV[3]=expected generation, ARGV[4]=grace window ms (0 = tombstone off).
     */
    protected const LUA_SAVE_WITH_LEASE = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
        if current == false then current = '0' end
        if current ~= ARGV[3] then return 0 end
        local window = tonumber(ARGV[4]) or 0
        if window > 0 then
            local tomb = redis.call('HGET', KEYS[1], '__utopia_tomb__')
            if tomb ~= false then
                local deadline = tonumber(tomb)
                if deadline ~= nil then
                    local t = redis.call('TIME')
                    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
                    -- 2nd clause ignores a deadline left by a since-rewound clock
                    if now < deadline and (deadline - now) <= window * 1000 then
                        return 0
                    end
                end
                redis.call('HDEL', KEYS[1], '__utopia_tomb__')
            end
        end
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
        return 1
        LUA;

    /**
     * Drop $key's value fields, advance its generation (so an in-flight reader
     * can't re-cache stale data), and stamp the tombstone when ARGV[1] > 0.
     * Returns value fields removed (reserved fields excluded), preserving purge()
     * semantics. Single-key. KEYS[1]=key; ARGV[1]=grace window ms.
     */
    protected const LUA_PURGE_BUMP = <<<'LUA'
        local gen = '__utopia_gen__'
        local tomb = '__utopia_tomb__'
        local removed = redis.call('HLEN', KEYS[1]) - redis.call('HEXISTS', KEYS[1], gen) - redis.call('HEXISTS', KEYS[1], tomb)
        local current = redis.call('HGET', KEYS[1], gen)
        local next = (tonumber(current) or 0) + 1
        redis.call('DEL', KEYS[1])
        redis.call('HSET', KEYS[1], gen, next)
        local window = tonumber(ARGV[1]) or 0
        if window > 0 then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            redis.call('HSET', KEYS[1], tomb, now + window * 1000)
        end
        return removed
        LUA;

    /**
     * Delete one $hash field, advance the generation, and stamp the tombstone when
     * ARGV[2] > 0 (key-level, so it also briefly blocks re-caching sibling fields).
     * Single-key. KEYS[1]=key; ARGV[1]=field, ARGV[2]=grace window ms.
     */
    protected const LUA_PURGE_FIELD = <<<'LUA'
        local removed = redis.call('HDEL', KEYS[1], ARGV[1])
        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
        local next = (tonumber(current) or 0) + 1
        redis.call('HSET', KEYS[1], '__utopia_gen__', next)
        local window = tonumber(ARGV[2]) or 0
        if window > 0 then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            redis.call('HSET', KEYS[1], '__utopia_tomb__', now + window * 1000)
        end
        return removed
        LUA;

    /**
     * Milliseconds after a purge during which saveWithLease() is refused. Size to
     * the worst-case read-after-write staleness; 0 disables the tombstone.
     */
    protected int $leaseGraceWindow = 0;

    protected readonly Envelope $envelope;

    public function __construct(Codec $codec = new Json())
    {
        $this->envelope = new Envelope($codec);
    }

    /**
     * @param  int  $milliseconds grace window after a purge (0 disables the tombstone)
     */
    public function setLeaseGraceWindow(int $milliseconds): static
    {
        $this->leaseGraceWindow = max(0, $milliseconds);

        return $this;
    }

    public function getLeaseGraceWindow(): int
    {
        return $this->leaseGraceWindow;
    }

    public function getGeneration(string $key): string
    {
        $gen = $this->leaseHget($key, self::GENERATION_FIELD);

        return \is_string($gen) ? $gen : '0';
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        if ($this->isReserved($hash)) {
            return false;
        }

        try {
            $value = $this->envelope->encode($data, time());
            $stored = $this->leaseRun(self::LUA_SAVE_WITH_LEASE, $key, [
                $hash, $value, $generation, (string) $this->leaseGraceWindow,
            ]);

            return $stored ? $data : false;
        } catch (Throwable) {
            return false;
        }
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash !== '' && $hash !== '0') {
            if ($this->isReserved($hash)) {
                return false;
            }

            return (bool) $this->leaseRun(self::LUA_PURGE_FIELD, $key, [$hash, (string) $this->leaseGraceWindow]);
        }

        return (bool) $this->leaseRun(self::LUA_PURGE_BUMP, $key, [(string) $this->leaseGraceWindow]);
    }

    /** Reserved internal fields must never be reachable through the public field API. */
    protected function isReserved(string $hash): bool
    {
        return $hash === self::GENERATION_FIELD || $hash === self::TOMBSTONE_FIELD;
    }

    /**
     * Memoized SHA1 digests of the Lua scripts, keyed by body. Only ever holds
     * the three constants above; a plain cache to avoid re-hashing per call.
     *
     * @var array<string, string>
     */
    private static array $scriptSha = [];

    /**
     * Dispatch a single-key script by digest — EVALSHA first, resending the body
     * via EVAL only when the server hasn't cached it yet (NoScript). KEYS[1] =
     * $key, ARGV = $args in order. Keeps the steady-state frame ~40 bytes.
     *
     * @param  array<int, int|string>  $args
     */
    private function leaseRun(string $script, string $key, array $args): mixed
    {
        try {
            return $this->leaseEvalSha($this->shaFor($script), $key, $args);
        } catch (NoScript) {
            return $this->leaseEval($script, $key, $args);
        }
    }

    /** SHA1 digest Redis identifies a cached script by — the digest of its body. */
    private function shaFor(string $script): string
    {
        return self::$scriptSha[$script] ??= sha1($script);
    }

    /**
     * Run EVALSHA of $sha — KEYS[1] = $key, ARGV = $args in order — over the
     * concrete adapter's transport, returning the script's reply.
     *
     * @param  array<int, int|string>  $args
     *
     * @throws NoScript when the server has no script cached for $sha, so
     *                   leaseRun() can resend the body via leaseEval()
     */
    abstract protected function leaseEvalSha(string $sha, string $key, array $args): mixed;

    /**
     * Run a single-key Redis EVAL — KEYS[1] = $key, ARGV = $args in order — over
     * the concrete adapter's transport, returning the script's reply. Used as the
     * one-time fallback that re-caches the script after a NoScript.
     *
     * @param  array<int, int|string>  $args
     */
    abstract protected function leaseEval(string $script, string $key, array $args): mixed;

    /**
     * HGET $field from hash $key; returns the raw value, or false when absent.
     */
    abstract protected function leaseHget(string $key, string $field): mixed;
}
