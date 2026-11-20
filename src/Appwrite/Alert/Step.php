<?php

namespace Appwrite\Alert;

/**
 * One step of an escalation policy: who it notifies, and how long it waits
 * first, after the alert fires for the first step and after the previous step
 * for the others.
 */
readonly class Step
{
    /**
     * @param list<string> $roles organization roles whose confirmed members it notifies
     * @param list<string> $userIds organization members it notifies, whatever their role
     */
    public function __construct(
        public int $delay,
        public array $roles = [],
        public array $userIds = [],
    ) {
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function fromArray(array $step): self
    {
        return new self(
            (int) ($step['delay'] ?? 0),
            \array_values(\array_unique(\array_map('strval', $step['roles'] ?? []))),
            \array_values(\array_unique(\array_map('strval', $step['userIds'] ?? []))),
        );
    }

    /**
     * @return array{delay: int, roles: list<string>, userIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'delay' => $this->delay,
            'roles' => $this->roles,
            'userIds' => $this->userIds,
        ];
    }
}
