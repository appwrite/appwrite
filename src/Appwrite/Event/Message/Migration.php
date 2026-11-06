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

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'migration' => $this->migration->getArrayCopy(),
            'platform' => $this->platform,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            migration: new Document($data['migration'] ?? []),
            platform: $data['platform'] ?? [],
        );
    }
}
