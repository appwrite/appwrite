<?php

namespace Utopia\Abuse\Challenges\ProofOfWork;

use Throwable;

/**
 * Result of {@see \Utopia\Abuse\Challenges\ProofOfWork::verify()}. Claims are
 * the signed token's, set once the token is known to belong to this client.
 */
final readonly class Result
{
    /**
     * @param  array<mixed>  $claims
     * @param  int  $elapsedMs  Time between issue and verify
     * @param  Throwable|null  $nonceError  Why the nonce store failed, when it did
     */
    public function __construct(
        public Status $status,
        public array $claims = [],
        public int $difficulty = 0,
        public int $elapsedMs = 0,
        public ?Throwable $nonceError = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === Status::Valid;
    }
}
