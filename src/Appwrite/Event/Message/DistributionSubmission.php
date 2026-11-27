<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class DistributionSubmission extends Base
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly Document $project,
        public readonly DistributionSubmissionAction $action,
        public readonly string $id,
        public readonly array $data = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'action' => $this->action->value,
            'id' => $this->id,
            'data' => $this->data,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (empty($data['action'])) {
            throw new \InvalidArgumentException('Missing action in distribution submission message payload');
        }

        $action = DistributionSubmissionAction::tryFrom($data['action'])
            ?? throw new \InvalidArgumentException('Invalid action in distribution submission message payload: ' . $data['action']);

        if (empty($data['id'])) {
            throw new \InvalidArgumentException('Missing id in distribution submission message payload');
        }

        return new self(
            project: new Document($data['project'] ?? []),
            action: $action,
            id: $data['id'],
            data: $data['data'] ?? [],
        );
    }
}
