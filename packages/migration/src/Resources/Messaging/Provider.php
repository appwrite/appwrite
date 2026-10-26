<?php

namespace Utopia\Migration\Resources\Messaging;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Provider extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param string $provider
     * @param string $type
     * @param bool $enabled
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly string $provider,
        private readonly string $type,
        private readonly bool $enabled = true,
        private readonly array $credentials = [],
        private readonly array $options = [],
        protected string $createdAt = '',
        protected string $updatedAt = '',
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
            $array['provider'] ?? '',
            $array['type'] ?? '',
            $array['enabled'] ?? true,
            $array['credentials'] ?? [],
            $array['options'] ?? [],
            $array['createdAt'] ?? '',
            $array['updatedAt'] ?? '',
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
            'provider' => $this->provider,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'credentials' => $this->credentials,
            'options' => $this->options,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROVIDER;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_MESSAGING;
    }

    public function getProviderName(): string
    {
        return $this->name;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
