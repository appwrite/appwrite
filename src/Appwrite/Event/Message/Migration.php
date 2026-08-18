<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Migration extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $migration,
        public readonly array $platform = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'migration' => $this->migration->getArrayCopy(),
            'platform' => $this->platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: Payload::document($data['project'] ?? []),
            migration: Payload::document($data['migration'] ?? []),
            platform: $data['platform'] ?? [],
        );
    }
}
