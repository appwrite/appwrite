<?php

namespace Appwrite\Event\Message;

/**
 * A pull request to review, for the review service (appwrite-labs/hansi). No Appwrite worker
 * consumes it: the service reads the payload as JSON, so its shape is that service's contract
 * (packages/appwrite/src/payload.ts there), not an internal one.
 */
final class Review extends Base
{
    /**
     * @param array{projectId: string, projectInternalId: string, repositoryId: string, installationId: string, providerInstallationId: string, providerRepositoryId: string, owner: string, name: string} $repository
     * @param array{kind: string, comment?: array{id: int, kind: string}} $trigger
     */
    public function __construct(
        public readonly string $reviewId,
        public readonly array $repository,
        public readonly int $pullNumber,
        public readonly string $headSha,
        public readonly array $trigger,
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'review',
            'reviewId' => $this->reviewId,
            'repository' => $this->repository,
            'pullNumber' => $this->pullNumber,
            'headSha' => $this->headSha,
            'trigger' => $this->trigger,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            reviewId: $data['reviewId'] ?? '',
            repository: $data['repository'] ?? [],
            pullNumber: (int) ($data['pullNumber'] ?? 0),
            headSha: $data['headSha'] ?? '',
            trigger: $data['trigger'] ?? [],
        );
    }
}
