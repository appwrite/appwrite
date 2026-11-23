<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class DistributionBuild extends Base
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly Document $project,
        public readonly DistributionBuildAction $action,
        public readonly string $id = '',
        public readonly string $event = '',
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
            'event' => $this->event,
            'data' => $this->data,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (empty($data['action'])) {
            throw new \InvalidArgumentException('Missing action in distribution build message payload');
        }

        $action = DistributionBuildAction::tryFrom($data['action'])
            ?? throw new \InvalidArgumentException('Invalid action in distribution build message payload: ' . $data['action']);

        return new self(
            project: new Document($data['project'] ?? []),
            action: $action,
            id: $data['id'] ?? '',
            event: $data['event'] ?? '',
            data: $data['data'] ?? [],
        );
    }
}
