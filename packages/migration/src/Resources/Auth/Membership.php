<?php

namespace Utopia\Migration\Resources\Auth;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Represents a membership of a user in a team
 */
class Membership extends Resource
{
    /**
     * @param string $id
     * @param Team $team
     * @param User $user
     * @param array<string> $roles
     * @param bool $active
     */
    public function __construct(
        string $id,
        private readonly Team  $team,
        private readonly User  $user,
        private readonly array $roles = [],
        private readonly bool  $active = true
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
            Team::fromArray($array['team'] ?? []),
            User::fromArray($array['user'] ?? []),
            $array['roles'] ?? [],
            $array['active'] ?? true
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'team' => $this->team,
            'user' => $this->user,
            'roles' => $this->roles,
            'active' => $this->active,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_MEMBERSHIP;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return array<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getActive(): bool
    {
        return $this->active;
    }
}
