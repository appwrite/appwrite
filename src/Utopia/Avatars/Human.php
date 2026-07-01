<?php

namespace Utopia\Avatars;

class Human
{
    public function __construct(
        public string $github = '',
        public string $email = '',
        public string $emailHash = '',
    ) {
    }

    public function getGravatarHash(): string
    {
        if (!empty($this->emailHash)) {
            return $this->emailHash;
        }

        if (!empty($this->email)) {
            return Adapter\Human\Gravatar::hashEmail($this->email);
        }

        return '';
    }

    public function getIdentifier(string $param): string
    {
        return match ($param) {
            'github' => $this->github,
            'emailHash' => $this->getGravatarHash(),
            default => '',
        };
    }

    public function hasIdentifier(): bool
    {
        return !empty($this->github) || !empty($this->email) || !empty($this->emailHash);
    }
}
