<?php

namespace Utopia\Abuse\Challenge\ProofOfWork;

use Throwable;
use Utopia\Abuse\Challenge\Result as ChallengeResult;
use Utopia\Abuse\Challenge\Status;

/**
 * A proof-of-work result: how hard the answered challenge was and how long the
 * client took, both 0 when the token couldn't be read.
 */
final readonly class Result extends ChallengeResult
{
    /**
     * @param  array<mixed>  $claims
     * @param  int  $difficulty  Leading zero bits the answer had to reach
     * @param  int  $elapsedMs  Time between issue and verify
     */
    public function __construct(
        Status $status,
        array $claims = [],
        public int $difficulty = 0,
        public int $elapsedMs = 0,
        ?Throwable $error = null,
    ) {
        parent::__construct($status, $claims, $error);
    }
}
