<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class StatsResources extends Base
{
    /**
     * @param Document $project
     * @param array<int, array{metric: string, value: int, service?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, ordinal?: int}> $gauges
     */
    public function __construct(
        public readonly Document $project,
        public readonly array $gauges = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'gauges' => $this->gauges,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            gauges: $data['gauges'] ?? [],
        );
    }
}
