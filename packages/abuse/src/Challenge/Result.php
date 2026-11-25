<?php

namespace Utopia\Abuse\Challenge;

use Throwable;

/**
 * Result of {@see \Utopia\Abuse\Challenge::verify()}. Claims are the signed
 * token's, set once the token is known to belong to these params. A kind with
 * more to report extends it, as {@see ProofOfWork\Result} does.
 */
readonly class Result
{
    /**
     * @param  array<mixed>  $claims
     * @param  Throwable|null  $error  Why a store the challenge relies on failed, when it did
     */
    public function __construct(
        public Status $status,
        public array $claims = [],
        public ?Throwable $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === Status::Valid;
    }
}
