<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class StatsResources extends Base
{
    /**
     * @param Document $project
     * @param array<int, array{metric: string, value: int}> $gauges
     */
    public function __construct(
        public readonly Document $project,
        public readonly array $gauges = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'gauges' => $this->gauges,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            gauges: $data['gauges'] ?? [],
        );
    }
}
