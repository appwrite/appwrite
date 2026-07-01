<?php

namespace Utopia\Avatars;

class Company
{
    public function __construct(
        public string $domain = '',
    ) {
    }

    public function getIdentifier(string $param): string
    {
        return match ($param) {
            'domain' => $this->domain,
            default => '',
        };
    }

    public function hasIdentifier(): bool
    {
        return !empty($this->domain);
    }
}
