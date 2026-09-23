<?php

declare(strict_types=1);

namespace Utopia\UserAgent;

final readonly class OperatingSystem
{
    public function __construct(
        public ?string $code = null,
        public ?string $name = null,
        public ?string $version = null,
    ) {}

    public function isKnown(): bool
    {
        return $this->name !== null;
    }

    /**
     * @return array{code: ?string, name: ?string, version: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
