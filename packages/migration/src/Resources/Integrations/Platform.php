<?php

namespace Utopia\Migration\Resources\Integrations;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Platform extends Resource
{
    /**
     * @param string $id
     * @param string $type
     * @param string $name
     * @param string $key
     * @param string $store
     * @param string $hostname
     * @param string $createdAt
     * @param string $updatedAt
     */
    public function __construct(
        string $id,
        private readonly string $type,
        private readonly string $name,
        private readonly string $key = '',
        private readonly string $store = '',
        private readonly string $hostname = '',
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['type'],
            $array['name'],
            $array['key'] ?? '',
            $array['store'] ?? '',
            $array['hostname'] ?? '',
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
            'type' => $this->type,
            'name' => $this->name,
            'key' => $this->key,
            'store' => $this->store,
            'hostname' => $this->hostname,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PLATFORM;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_INTEGRATIONS;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPlatformName(): string
    {
        return $this->name;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getStore(): string
    {
        return $this->store;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }
}
