<?php

namespace Appwrite\Event\Message;

/**
 * A pull request comment for the review service (appwrite/hansi) to answer. Like a review, it
 * goes out on the reviews queue in the service's own job shape (packages/appwrite/src/payload.ts).
 */
final class ReviewChat extends Base
{
    /**
     * @param array{projectId: string, projectInternalId: string, repositoryId: string, installationId: string, providerInstallationId: string, providerRepositoryId: string, owner: string, name: string} $repository
     * @param array{id: int, kind: string, rootCommentId?: int, author: string, authorAssociation: string, url: string} $comment
     * @param bool $unmentioned A review thread reply that does not mention the reviewer, answered only in a thread on one of its own findings.
     */
    public function __construct(
        public readonly array $repository,
        public readonly int $pullNumber,
        public readonly array $comment,
        public readonly bool $unmentioned = false,
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => 'chat',
            'repository' => $this->repository,
            'pullNumber' => $this->pullNumber,
            'comment' => $this->comment,
        ];

        if ($this->unmentioned) {
            $data['unmentioned'] = true;
        }

        return $data;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            repository: $data['repository'] ?? [],
            pullNumber: (int) ($data['pullNumber'] ?? 0),
            comment: $data['comment'] ?? [],
            unmentioned: (bool) ($data['unmentioned'] ?? false),
        );
    }
}
