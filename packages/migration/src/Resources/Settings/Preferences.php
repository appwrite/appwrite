<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Singleton resource carrying the project's preferences (key-value object).
 * One per project; destination replaces the whole object.
 */
class Preferences extends Resource
{
    /**
     * @param array<string, mixed> $preferences
     */
    public function __construct(
        string $id,
        private readonly array $preferences = [],
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array{id: string, preferences?: array<string, mixed>, createdAt?: string, updatedAt?: string} $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['preferences'] ?? [],
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
            'preferences' => $this->preferences,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_PREFERENCES;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return $this->preferences;
    }
}
