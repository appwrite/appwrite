<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

class Usage extends Base
{
    /**
     * @param Document $project
     * @param array<array{key: string, value: int}> $metrics
     * @param array<Document> $reduce
     */
    public function __construct(
        public readonly Document $project,
        public readonly array $metrics,
        public readonly array $reduce = [],
    ) {
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'metrics' => $this->metrics,
            'reduce' => array_map(fn (Document $doc) => $doc->getArrayCopy(), $this->reduce),
        ];
    }

    /**
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore new.static (subclass constructors are backwards-compatible via optional params) */
        return new static(
            project: new Document($data['project'] ?? []),
            metrics: $data['metrics'] ?? [],
            reduce: array_map(fn (array $doc) => new Document($doc), $data['reduce'] ?? []),
        );
    }
}
