<?php

namespace Utopia\Abuse\Challenges\ProofOfWork\Nonce;

/**
 * Atomic single-use nonce consumption — the PoW replay guard. Abstracted from
 * its backing store ({@see RedisPool}) so {@see \Utopia\Abuse\Challenges\ProofOfWork} can be unit-tested with an
 * in-memory implementation. Nonces are scoped per project.
 */
interface Consumer
{
    /**
     * Consume a project-scoped nonce. Returns {@see Outcome::Fresh} on the
     * first claim, {@see Outcome::Consumed} on a replay, and
     * {@see Outcome::Unavailable} when the backing store cannot be reached.
     */
    public function consume(string $projectId, string $nonce, int $ttl): Outcome;
}
