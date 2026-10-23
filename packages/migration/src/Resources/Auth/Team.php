<?php

namespace Utopia\Migration\Resources\Auth;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Team extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string, mixed> $preferences
     * @param array<User> $members
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly array $preferences = [],
        private readonly array $members = []
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
            $array['name'],
            $array['preferences'] ?? [],
            $array['members'] ?? []
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
            'preferences' => $this->preferences,
            'members' => $this->members,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_TEAM;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    public function getTeamName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return $this->preferences;
    }

    /**
     * @return array<User>
     */
    public function getMembers(): array
    {
        return $this->members;
    }
}
