<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Executions extends Base
{
    /**
     * @param array<Document> $executions
     */
    public function __construct(
        public readonly Document $project,
        public readonly array $executions,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'executions' => \array_map(
                fn (Document $execution) => $execution->getArrayCopy(),
                $this->executions
            ),
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: Payload::document($data['project'] ?? []),
            executions: \array_map(
                fn (mixed $execution) => Payload::document($execution),
                $data['executions'] ?? []
            ),
        );
    }
}
