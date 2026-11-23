<?php

namespace Utopia\Abuse\Challenge\ProofOfWork;

use Utopia\Abuse\Challenge\Token as ChallengeToken;

/**
 * A proof-of-work token: the value to send back, the seed the client hashes
 * its answers against, and how many leading zero bits the hash must have.
 */
final readonly class Token extends ChallengeToken
{
    public function __construct(
        string $value,
        public string $seed,
        public int $difficulty,
    ) {
        parent::__construct($value);
    }
}
