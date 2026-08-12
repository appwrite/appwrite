<?php

declare(strict_types=1);

namespace Utopia\Lock;

use Closure;
use InvalidArgumentException;
use LogicException;
use Redis;
use Utopia\Lock\Exception\Contention;

final class Distributed implements Lock
{
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        else
            return 0
        end
        LUA;

    private const string REFRESH_SCRIPT = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("expire", KEYS[1], ARGV[2])
        else
            return 0
        end
        LUA;

    private ?string $token = null;

    private ?Closure $logger = null;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $key,
        private readonly int $ttl = 600,
    ) {}

    /**
     * @param  Closure(string): void  $logger
     */
    public function setLogger(Closure $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    #[\Override]
    public function acquire(float $timeout = 0.0): bool
    {
        if ($this->tryAcquire()) {
            return true;
        }

        if ($timeout <= 0.0) {
            return false;
        }

        $deadline = microtime(true) + $timeout;
        $delay = 0.05;

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            $sleep = min($delay, $remaining);
            if ($sleep > 0.0) {
                usleep((int) ($sleep * 1_000_000));
            }

            if ($this->tryAcquire()) {
                return true;
            }

            $this->log("Lock contention for {$this->key}, retrying");
            $delay = min($delay * 2.0, 1.0);
        }

        $this->log("Failed to acquire lock for {$this->key} within {$timeout}s");

        return false;
    }

    #[\Override]
    public function tryAcquire(): bool
    {
        $token = $this->generateToken();
        $acquired = $this->redis->set($this->key, $token, ['NX', 'EX' => $this->ttl]);

        if ($acquired) {
            $this->token = $token;

            return true;
        }

        return false;
    }

    #[\Override]
    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        $this->runScript(self::RELEASE_SCRIPT, [$this->key, $this->token]);
        $this->token = null;
    }

    #[\Override]
    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new Contention("Failed to acquire distributed lock: {$this->key}");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function refresh(): bool
    {
        if ($this->token === null) {
            return false;
        }

        return $this->runScript(self::REFRESH_SCRIPT, [$this->key, $this->token, (string) $this->ttl]) === 1;
    }

    public function isHeld(): bool
    {
        if ($this->token === null) {
            return false;
        }

        return $this->redis->get($this->key) === $this->token;
    }

    /**
     * Use a token minted by another instance for this lock key.
     *
     * Token adoption lets a holder delegate token-guarded commands such as
     * {@see refresh()} to an instance backed by a different Redis connection.
     * Possession of the token carries the same authority as the original
     * instance: {@see release()} can also release the lease while it still owns
     * the key.
     */
    public function adopt(string $token): self
    {
        if ($token === '') {
            throw new InvalidArgumentException('Token must not be empty');
        }

        if ($this->token !== null) {
            throw new LogicException('Cannot replace the token of a distributed lock');
        }

        $this->token = $token;

        return $this;
    }

    /**
     * The value the last successful acquisition wrote to the key, or null before
     * one and after {@see release()}.
     *
     * This is what the lock believes it wrote, not proof that the key still holds
     * it: an expired TTL and a failed {@see refresh()} both leave the value here
     * untouched. {@see isHeld()} and {@see refresh()} are what answer whether the
     * lease is live.
     *
     * Being unchanged by the loss is what makes it useful. A caller that records
     * what it did while holding the lease can store this token with the record and
     * refuse a later write whose stored token no longer matches, which is the only
     * way to make the write itself conditional on the lease: a refresh proves
     * ownership at the instant it returns, not at the instant the write commits.
     *
     * A new value is generated on every acquisition, so work recorded under a lapsed
     * lease does not compare equal to work recorded under the lease that replaced it.
     *
     * It is also the literal value on the key, so an operator can read the key back
     * and compare it against a record directly.
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runScript(string $script, array $arguments): mixed
    {
        $method = 'eval';

        return $this->redis->$method($script, $arguments, 1);
    }

    private function generateToken(): string
    {
        return gethostname() . ':' . getmypid() . ':' . uniqid('', true);
    }

    private function log(string $message): void
    {
        if ($this->logger instanceof \Closure) {
            ($this->logger)($message);
        }
    }
}
