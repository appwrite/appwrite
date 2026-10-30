<?php

namespace Utopia\Cache\Adapter\Redis;

use Throwable;
use Utopia\Cache\Codec;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\Envelope;

/** Single-key versioned reads, CAS and invalidation shared by Redis transports. */
abstract class Versioned implements \Utopia\Cache\Feature\Versioned
{
    /**
     * Reserved hash field that holds a key's generation alongside its value
     * fields. Keeping the generation inside the value's own hash makes every
     * lease operation single-key (one shard): no sidecar key to collide with or
     * scan, and the scripts stay correct under Redis Cluster / multi-threaded
     * backends (e.g. Dragonfly), where a multi-key script would span shards.
     * Callers must not use this as a cache hash/field.
     */
    protected const VERSION_FIELD = '__utopia_version__';

    /**
     * Reserved hash field: epoch-µs deadline until which saveWithLease() is
     * refused after a purge, so a reader whose DB read lagged the write can't
     * re-cache stale data under a still-valid generation token. Not a cache field.
     */
    protected const TOMBSTONE_FIELD = '__utopia_tomb__';

    private const string LUA_PURGE = <<<'LUA'
        local removed = 0
        if ARGV[1] == '' then
            removed = redis.call('HLEN', KEYS[1])
                - redis.call('HEXISTS', KEYS[1], '__utopia_version__')
                - redis.call('HEXISTS', KEYS[1], '__utopia_tomb__')
            redis.call('DEL', KEYS[1])
        else
            removed = redis.call('HDEL', KEYS[1], ARGV[1])
            redis.call('HDEL', KEYS[1], '__utopia_version__')
        end
        local window = tonumber(ARGV[2])
        if window > 0 then
            local t = redis.call('TIME')
            redis.call('HSET', KEYS[1], '__utopia_tomb__', tonumber(t[1]) * 1000000 + tonumber(t[2]) + window * 1000)
        end
        return removed
        LUA;

    private const string LUA_VERSION = <<<'LUA'
        local version = redis.call('HGET', KEYS[1], '__utopia_version__')
        if version == false then
            version = ARGV[1]
            redis.call('HSET', KEYS[1], '__utopia_version__', version)
        end
        local value = false
        if ARGV[2] ~= '' then value = redis.call('HGET', KEYS[1], ARGV[2]) end
        return {version, value}
        LUA;

    private const string LUA_COMPARE_AND_SWAP = <<<'LUA'
        if redis.call('HGET', KEYS[1], '__utopia_version__') ~= ARGV[1] then return 0 end
        local deadline = tonumber(redis.call('HGET', KEYS[1], '__utopia_tomb__'))
        local window = tonumber(ARGV[5])
        if deadline ~= nil and window > 0 then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            if now < deadline and deadline - now <= window * 1000 then return 0 end
            redis.call('HDEL', KEYS[1], '__utopia_tomb__')
        end
        redis.call('HSET', KEYS[1], ARGV[2], ARGV[3], '__utopia_version__', ARGV[4])
        return 1
        LUA;

    public function supportsVersioning(): bool
    {
        return true;
    }

    public function getVersion(string $key): string
    {
        $result = $this->leaseRun(self::LUA_VERSION, $key, [\bin2hex(\random_bytes(16)), '']);
        if (! \is_array($result) || ! \is_string($result[0] ?? null)) {
            throw new \Utopia\Cache\Exception\Unavailable('Unable to obtain cache version');
        }

        return $result[0];
    }

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array
    {
        $result = $this->leaseRun(self::LUA_VERSION, $key, [\bin2hex(\random_bytes(16)), $key]);
        if (! \is_array($result) || ! \is_string($result[0] ?? null)) {
            throw new \Utopia\Cache\Exception\Unavailable('Unable to read cache snapshot');
        }

        return [
            'version' => $result[0],
            'value' => \is_string($result[1] ?? null) ? $this->envelope->decode($result[1], PHP_INT_MAX, \time()) : false,
        ];
    }

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        $hash = $hash === '' ? $key : $hash;
        if ($this->isReserved($hash)) {
            return false;
        }

        try {
            $next = \bin2hex(\random_bytes(16));
            $stored = $this->leaseRun(self::LUA_COMPARE_AND_SWAP, $key, [
                $version, $hash, $this->envelope->encode($data, \time()),
                $next, (string) $this->leaseGraceWindow,
            ]);

            return $stored ? $next : false;
        } catch (Throwable $error) {
            $this->recordWriteFailure('compareAndSwap', $error);

            throw $error;
        }
    }

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

    /**
     * A write that reports its failure as false hands the cause here, so an adapter can make it observable.
     */
    protected function recordWriteFailure(string $operation, Throwable $error): void
    {
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($this->isReserved($hash)) {
            return false;
        }

        return (bool) $this->leaseRun(self::LUA_PURGE, $key, [$hash, (string) $this->leaseGraceWindow]);
    }

    /** Reserved internal fields must never be reachable through the public field API. */
    protected function isReserved(string $hash): bool
    {
        return in_array($hash, [self::TOMBSTONE_FIELD, self::VERSION_FIELD], true);
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
}
