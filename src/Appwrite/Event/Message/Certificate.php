<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final readonly class Certificate extends Base
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var array<string, mixed> $project */
        $project = is_array($data['project'] ?? null) ? $data['project'] : [];
        /** @var array<string, mixed> $domain */
        $domain = is_array($data['domain'] ?? null) ? $data['domain'] : [];

        return new self(
            project: !empty($project) ? new Document($project) : null,
            domain: !empty($domain) ? new Document($domain) : null,
            skipRenewCheck: (bool) ($data['skipRenewCheck'] ?? false),
            validationDomain: is_string($data['validationDomain'] ?? null) ? $data['validationDomain'] : null,
            action: is_string($data['action'] ?? null) ? $data['action'] : self::ACTION_GENERATION,
        );
    }
}
