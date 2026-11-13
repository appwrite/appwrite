<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * One evaluation of a monitor. The project lets the worker resolve the
 * monitor's project and its database before it reads the monitor.
 */
final class Alert extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly string $monitorId = '',
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'monitorId' => $this->monitorId,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            monitorId: $data['monitorId'] ?? '',
        );
    }
}
