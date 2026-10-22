<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Screenshot extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly string $deploymentId,
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'deploymentId' => $this->deploymentId,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            deploymentId: $data['deploymentId'] ?? '',
        );
    }
}
