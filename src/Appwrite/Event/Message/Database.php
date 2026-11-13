<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Database extends Base
{
    public function __construct(
        public readonly ?Document $project = null,
        public readonly string $type = '',
        public readonly ?Document $table = null,
        public readonly ?Document $row = null,
        public readonly ?Document $collection = null,
        public readonly ?Document $document = null,
        public readonly ?Document $database = null,
        public readonly array $events = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => $this->project === null ? null : ProjectContext::fromDocument($this->project)->toArray(),
            'type' => $this->type,
            'table' => $this->table?->getArrayCopy(),
            'row' => $this->row?->getArrayCopy(),
            'collection' => $this->collection?->getArrayCopy(),
            'document' => $this->document?->getArrayCopy(),
            'database' => $this->database?->getArrayCopy(),
            'events' => $this->events,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            type: $data['type'] ?? '',
            table: !empty($data['table']) ? new Document($data['table']) : null,
            row: !empty($data['row']) ? new Document($data['row']) : null,
            collection: !empty($data['collection']) ? new Document($data['collection']) : null,
            document: !empty($data['document']) ? new Document($data['document']) : null,
            database: !empty($data['database']) ? new Document($data['database']) : null,
            events: $data['events'] ?? [],
        );
    }
}
