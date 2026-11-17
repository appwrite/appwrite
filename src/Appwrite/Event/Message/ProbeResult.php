<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * A prober's check of one monitor, for the alerts worker to record. It rides the
 * alerts queue beside the `Alert` message, whose project and monitor it carries,
 * so the worker resolves the project the same way for both.
 */
final class ProbeResult extends Base
{
    /**
     * @param array<string, mixed> $result the prober's `ProbeResult`, as it published it
     */
    public function __construct(
        public readonly Document $project,
        public readonly string $monitorId = '',
        public readonly array $result = [],
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'monitorId' => $this->monitorId,
            'result' => $this->result,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            monitorId: $data['monitorId'] ?? '',
            result: $data['result'] ?? [],
        );
    }
}
