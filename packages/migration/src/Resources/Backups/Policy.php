<?php

namespace Utopia\Migration\Resources\Backups;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Policy extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string> $services
     * @param int $retention
     * @param string $schedule
     * @param bool $enabled
     * @param string $resourceId
     * @param string $resourceType
     */
    public function __construct(
        string $id = '',
        private readonly string $name = '',
        private readonly array $services = [],
        private readonly int $retention = 0,
        private readonly string $schedule = '',
        private readonly bool $enabled = true,
        private readonly string $resourceId = '',
        private readonly string $resourceType = '',
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['name'] ?? '',
            $array['services'] ?? [],
            $array['retention'] ?? 0,
            $array['schedule'] ?? '',
            $array['enabled'] ?? true,
            $array['resourceId'] ?? '',
            $array['resourceType'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'services' => $this->services,
            'retention' => $this->retention,
            'schedule' => $this->schedule,
            'enabled' => $this->enabled,
            'resourceId' => $this->resourceId,
            'resourceType' => $this->resourceType,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_BACKUP_POLICY;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_BACKUPS;
    }

    public function getPolicyName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    public function getRetention(): int
    {
        return $this->retention;
    }

    public function getSchedule(): string
    {
        return $this->schedule;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }
}
