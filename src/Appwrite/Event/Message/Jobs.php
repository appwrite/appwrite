<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * A single jobs-service callback (CloudEvents envelope) handed off to the jobs
 * worker. `project` provides the DI context so the worker can resolve
 * `dbForProject`; `event` is the CloudEvent `type`; `data` is the event `data`
 * payload; `id` is the CloudEvent id used for de-duplication.
 */
final class Jobs extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly string $id,
        public readonly string $event,
        public readonly array $data = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'id' => $this->id,
            'event' => $this->event,
            'data' => $this->data,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            id: $data['id'] ?? '',
            event: $data['event'] ?? '',
            data: $data['data'] ?? [],
        );
    }
}
