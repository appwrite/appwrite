<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

/**
 * One evaluation of an alert rule. The project lets the worker resolve the
 * rule's project and its database before it reads the rule.
 */
final class Alert extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly string $ruleId = '',
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'ruleId' => $this->ruleId,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            ruleId: $data['ruleId'] ?? '',
        );
    }
}
