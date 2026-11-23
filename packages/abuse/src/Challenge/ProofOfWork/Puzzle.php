<?php

namespace Utopia\Abuse\Challenge\ProofOfWork;

use Utopia\Abuse\Challenge\Prompt;

/**
 * A proof-of-work prompt: the signed token to send back, the nonce to hash, and
 * how many leading zero bits the hash must have.
 */
final readonly class Puzzle extends Prompt
{
    public function __construct(
        string $token,
        public string $nonce,
        public int $difficulty,
    ) {
        parent::__construct($token);
    }
}
