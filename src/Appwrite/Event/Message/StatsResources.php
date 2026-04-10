<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class StatsResources extends Base
{
    public function __construct(
        public readonly Document $project,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
        );
    }
}
