<?php

namespace Utopia\Abuse\Challenges\ProofOfWork;

/**
 * Result of {@see \Utopia\Abuse\Challenges\ProofOfWork::verify()}: the verdict plus the projectId and resourceId
 * carried by (and authenticated by the signature of) the token. Both are trusted
 * from the token — there is nothing external to cross-check at verify time — and
 * determine which project/resource the clearance and usage are attributed to.
 */
final readonly class Result
{
    public function __construct(
        public Status $status,
        public string $projectId,
        public string $resourceId = '',
        public int $clearanceTtl = 0,
        public int $elapsedMs = 0,
        public int $difficulty = 0,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === Status::Valid;
    }
}
