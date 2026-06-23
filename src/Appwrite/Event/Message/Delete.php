<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Delete extends Base
{
    public function __construct(
        public readonly ?Document $project = null,
        public readonly string $type = '',
        public readonly ?Document $document = null,
        public readonly ?string $resource = null,
        public readonly ?string $resourceType = null,
        public readonly ?string $datetime = null,
        public readonly ?string $hourlyUsageRetentionDatetime = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project?->getArrayCopy(),
            'type' => $this->type,
            'document' => $this->document?->getArrayCopy(),
            'resource' => $this->resource,
            'resourceType' => $this->resourceType,
            'datetime' => $this->datetime,
            'hourlyUsageRetentionDatetime' => $this->hourlyUsageRetentionDatetime,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            type: $data['type'] ?? '',
            document: !empty($data['document']) ? new Document($data['document']) : null,
            resource: $data['resource'] ?? null,
            resourceType: $data['resourceType'] ?? null,
            datetime: $data['datetime'] ?? null,
            hourlyUsageRetentionDatetime: $data['hourlyUsageRetentionDatetime'] ?? null,
        );
    }
}
