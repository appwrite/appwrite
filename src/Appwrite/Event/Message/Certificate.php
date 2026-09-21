<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Certificate extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $domain,
        public readonly bool $skipRenewCheck = false,
        public readonly ?string $validationDomain = null,
        public readonly string $action = \Appwrite\Event\Certificate::ACTION_GENERATION,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'domain' => $this->domain->getArrayCopy(),
            'skipRenewCheck' => $this->skipRenewCheck,
            'validationDomain' => $this->validationDomain,
            'action' => $this->action,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            domain: new Document($data['domain'] ?? []),
            skipRenewCheck: $data['skipRenewCheck'] ?? false,
            validationDomain: $data['validationDomain'] ?? null,
            action: $data['action'] ?? \Appwrite\Event\Certificate::ACTION_GENERATION,
        );
    }
}
