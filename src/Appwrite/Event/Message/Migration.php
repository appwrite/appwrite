<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * Producers publish only once the project has the V26 ownership schema. Workers
 * older than the claim protocol ignore it, so they cannot fence generations
 * during a rolling deployment.
 */
final class Migration extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $migration,
        public readonly array $platform = [],
        public readonly ?Document $terminal = null,
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'project' => $this->project->getArrayCopy(),
            'migration' => $this->migration->getArrayCopy(),
            'platform' => $this->platform,
        ];

        if ($this->terminal !== null) {
            $payload['terminal'] = $this->terminal->getArrayCopy();
        }

        return $payload;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            migration: new Document($data['migration'] ?? []),
            platform: $data['platform'] ?? [],
            terminal: !empty($data['terminal']) && \is_array($data['terminal'])
                ? new Document($data['terminal'])
                : null,
        );
    }
}
