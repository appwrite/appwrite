<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * An environment of the project: a separate project in the same organization,
 * billed under its parent. Only the environment itself is carried; its own
 * resources belong to a different project.
 */
class Environment extends Resource
{
    public function __construct(
        string $id,
        private readonly string $name,
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
            $array['name'] ?? '',
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
            'name' => $this->name,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_ENVIRONMENT;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    public function getEnvironmentName(): string
    {
        return $this->name;
    }
}
