<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Database extends Base
{
    public function __construct(
        public readonly ?Document $project = null,
        public readonly ?Document $user = null,
        public readonly string $type = '',
        public readonly ?Document $table = null,
        public readonly ?Document $row = null,
        public readonly ?Document $collection = null,
        public readonly ?Document $document = null,
        public readonly ?Document $database = null,
        public readonly array $events = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project?->getArrayCopy(),
            'user' => $this->user?->getArrayCopy(),
            'type' => $this->type,
            'table' => $this->table?->getArrayCopy(),
            'row' => $this->row?->getArrayCopy(),
            'collection' => $this->collection?->getArrayCopy(),
            'document' => $this->document?->getArrayCopy(),
            'database' => $this->database?->getArrayCopy(),
            'events' => $this->events,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: Payload::documentOrNull($data['project'] ?? null),
            user: Payload::documentOrNull($data['user'] ?? null),
            type: $data['type'] ?? '',
            table: Payload::documentOrNull($data['table'] ?? null),
            row: Payload::documentOrNull($data['row'] ?? null),
            collection: Payload::documentOrNull($data['collection'] ?? null),
            document: Payload::documentOrNull($data['document'] ?? null),
            database: Payload::documentOrNull($data['database'] ?? null),
            events: $data['events'] ?? [],
        );
    }
}
