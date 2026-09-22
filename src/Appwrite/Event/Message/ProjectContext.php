<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final readonly class ProjectContext
{
    public function __construct(
        public string $id = '',
        public string $sequence = '',
        public string $teamId = '',
        public string $teamInternalId = '',
        public string $createdAt = '',
        public string $region = '',
    ) {
    }

    public static function fromDocument(Document $project): self
    {
        return new self(
            id: $project->getId(),
            sequence: (string) $project->getSequence(),
            teamId: (string) $project->getAttribute('teamId', ''),
            teamInternalId: (string) $project->getAttribute('teamInternalId', ''),
            createdAt: (string) $project->getCreatedAt(),
            region: (string) $project->getAttribute('region', ''),
        );
    }

    public static function fromArray(array $project): self
    {
        return new self(
            id: (string) ($project['$id'] ?? ''),
            sequence: (string) ($project['$sequence'] ?? ''),
            teamId: (string) ($project['teamId'] ?? ''),
            teamInternalId: (string) ($project['teamInternalId'] ?? ''),
            createdAt: (string) ($project['$createdAt'] ?? ''),
            region: (string) ($project['region'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->id === '';
    }

    public function isConsole(): bool
    {
        return $this->id === 'console';
    }

    public function toArray(): array
    {
        return [
            '$id' => $this->id,
            '$sequence' => $this->sequence,
            'teamId' => $this->teamId,
            'teamInternalId' => $this->teamInternalId,
            '$createdAt' => $this->createdAt,
            'region' => $this->region,
        ];
    }
}
