<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Singleton settings resource carrying the project's per-service enable/disable
 * flags, keyed by ServiceId value. One per project; destination merges each
 * entry into the project's services map.
 */
class Services extends Resource
{
    /**
     * @param array<string, bool> $services Map of ServiceId string → enabled flag.
     */
    public function __construct(
        string $id,
        private readonly array $services = [],
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            (array) ($array['services'] ?? []),
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'services' => $this->services,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_SERVICES;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    /**
     * @return array<string, bool>
     */
    public function getServices(): array
    {
        return $this->services;
    }
}
