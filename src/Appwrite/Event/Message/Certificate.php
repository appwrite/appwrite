<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

readonly class Certificate extends Base
{
    public const string ACTION_DOMAIN_VERIFICATION = 'verification';
    public const string ACTION_GENERATION = 'generation';

    public function __construct(
        public ?Document $project = null,
        public ?Document $domain = null,
        public bool $skipRenewCheck = false,
        public ?string $validationDomain = null,
        public string $action = self::ACTION_GENERATION,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project?->getArrayCopy(),
            'domain' => $this->domain?->getArrayCopy(),
            'skipRenewCheck' => $this->skipRenewCheck,
            'validationDomain' => $this->validationDomain,
            'action' => $this->action,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            domain: !empty($data['domain']) ? new Document($data['domain']) : null,
            skipRenewCheck: $data['skipRenewCheck'] ?? false,
            validationDomain: $data['validationDomain'] ?? null,
            action: $data['action'] ?? self::ACTION_GENERATION,
        );
    }
}
