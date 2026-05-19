<?php

namespace Appwrite\Event\Message;

use Utopia\Config\Config;
use Utopia\Database\Document;

final class Build extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $resource,
        public readonly Document $deployment,
        public readonly string $type,
        public readonly ?Document $template = null,
        public readonly array $platform = [],
    ) {
    }

    public function toArray(): array
    {
        $platform = !empty($this->platform) ? $this->platform : Config::getParam('platform', []);

        return [
            'project' => $this->project->getArrayCopy(),
            'resource' => $this->resource->getArrayCopy(),
            'deployment' => $this->deployment->getArrayCopy(),
            'type' => $this->type,
            'template' => $this->template?->getArrayCopy(),
            'platform' => $platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            resource: new Document($data['resource'] ?? []),
            deployment: new Document($data['deployment'] ?? []),
            type: $data['type'] ?? '',
            template: !empty($data['template']) ? new Document($data['template']) : null,
            platform: $data['platform'] ?? [],
        );
    }
}
