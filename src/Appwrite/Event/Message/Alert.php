<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * One evaluation of a monitor, or with `alertId`, one due step of an alert's
 * escalation. The project lets the worker resolve the project and its database
 * before it reads either.
 */
final class Alert extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly string $monitorId = '',
        public readonly string $alertId = '',
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'monitorId' => $this->monitorId,
            'alertId' => $this->alertId,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            monitorId: $data['monitorId'] ?? '',
            alertId: $data['alertId'] ?? '',
        );
    }
}
