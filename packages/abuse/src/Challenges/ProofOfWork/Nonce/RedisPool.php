<?php

namespace Utopia\Abuse\Challenges\ProofOfWork\Nonce;

use Closure;
use Redis;
use Throwable;
use Utopia\Pools\Pool;

/**
 * Single-use store for proof-of-work nonces, backed by an atomic Redis
 * `SET key 1 NX EX <ttl>` keyed per project. First claim of a nonce wins
 * (Fresh); any later claim finds the key present and is a replay (Consumed).
 * A single fixed-expiry key (not a time-bucketed counter) keeps the guard
 * correct for the whole token lifetime.
 */
final readonly class RedisPool implements Consumer
{
    /**
     * @param Pool<Redis> $pool
     * @param string $prefix key prefix; keep it stable across deploys so nonces issued before a rollout stay single-use after it
     * @param (Closure(Throwable): void)|null $onUnavailable called with the error when Redis cannot be reached, so the caller can record the degraded window
     */
    public function __construct(
        private Pool $pool,
        private string $prefix = 'abuse:pow:',
        private ?Closure $onUnavailable = null,
    ) {
    }

    public function consume(string $projectId, string $nonce, int $ttl): Outcome
    {
        if ($nonce === '') {
            return Outcome::Consumed;
        }

        $key = $this->prefix . $projectId . ':' . $nonce;

        try {
            return $this->pool->use(function (Redis $redis) use ($key, $ttl): Outcome {
                $result = $redis->set($key, '1', ['nx', 'ex' => \max(1, $ttl)]);

                return $result === true ? Outcome::Fresh : Outcome::Consumed;
            });
        } catch (Throwable $error) {
            // Redis unreachable: fail open on the nonce (still validate the
            // solution) so a blip does not loop genuine solvers.
            if ($this->onUnavailable !== null) {
                ($this->onUnavailable)($error);
            }

            return Outcome::Unavailable;
        }
    }
}
