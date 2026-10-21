<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class ProjectVariable extends Resource
{
    public function __construct(
        string $id,
        private readonly string $key,
        private readonly string $value = '',
        private readonly bool $secret = false,
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
            $array['key'],
            $array['value'] ?? '',
            (bool) ($array['secret'] ?? false),
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
            'key' => $this->key,
            'value' => $this->value,
            'secret' => $this->secret,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_VARIABLE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }
}
