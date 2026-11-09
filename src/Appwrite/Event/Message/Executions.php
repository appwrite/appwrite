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

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'executions' => \array_map(
                fn (Document $execution) => $execution->getArrayCopy(),
                $this->executions
            ),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            executions: \array_map(
                fn (array $execution) => new Document($execution),
                $data['executions'] ?? []
            ),
        );
    }
}
