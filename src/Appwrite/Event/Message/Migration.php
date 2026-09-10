<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * Roll out the V26 schema first, then claim-aware migration workers while the
 * producer flag stays disabled, and only then enable producers. Older workers
 * ignore this protocol and cannot provide atomic generation claiming during a
 * rolling deployment.
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
