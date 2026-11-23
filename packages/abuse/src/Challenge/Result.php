<?php

namespace Utopia\Abuse\Challenge;

use Throwable;

/**
 * Result of {@see \Utopia\Abuse\Challenge::verify()}. Claims are the signed
 * token's, set once the token is known to belong to these params.
 */
final readonly class Result
{
    /**
     * @param  array<mixed>  $claims
     * @param  int  $difficulty  How hard the answered challenge was; 0 when the kind has no difficulty
     * @param  int  $elapsedMs  Time between issue and verify, when the kind records it
     * @param  Throwable|null  $error  Why a store the challenge relies on failed, when it did
     */
    public function __construct(
        public Status $status,
        public array $claims = [],
        public int $difficulty = 0,
        public int $elapsedMs = 0,
        public ?Throwable $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === Status::Valid;
    }
}
