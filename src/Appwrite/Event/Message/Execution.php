<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Execution extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $execution,
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'execution' => $this->execution->getArrayCopy(),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            execution: new Document($data['execution'] ?? []),
        );
    }
}
