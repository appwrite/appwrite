<?php

namespace Appwrite\Alert;

/**
 * Who one team notification of an alert goes to: the organization's confirmed
 * members holding one of `roles`, and those named in `userIds`. `key` names
 * the notification for deduplication; empty for the monitor's own announcement.
 */
readonly class Audience
{
    /**
     * @param list<string> $roles
     * @param list<string> $userIds
     */
    public function __construct(
        public array $roles,
        public array $userIds = [],
        public string $key = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->roles === [] && $this->userIds === [];
    }
}
