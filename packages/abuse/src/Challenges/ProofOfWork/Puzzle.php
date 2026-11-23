<?php

namespace Utopia\Abuse\Challenges\ProofOfWork;

/**
 * What a client needs to solve a challenge: the signed token to send back, the
 * nonce to hash, and how many leading zero bits the hash must have.
 */
final readonly class Puzzle
{
    public function __construct(
        public string $token,
        public string $nonce,
        public int $difficulty,
    ) {
    }
}
