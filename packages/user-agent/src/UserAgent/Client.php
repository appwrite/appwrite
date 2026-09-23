<?php

declare(strict_types=1);

namespace Utopia\UserAgent;

final readonly class Client
{
    public function __construct(
        public ?string $type = null,
        public ?string $code = null,
        public ?string $name = null,
        public ?string $version = null,
        public ?string $engine = null,
        public ?string $engineVersion = null,
    ) {}

    public function isKnown(): bool
    {
        return $this->name !== null;
    }

    public function isBrowser(): bool
    {
        return $this->type === 'browser';
    }

    /**
     * @return array{type: ?string, code: ?string, name: ?string, version: ?string, engine: ?string, engineVersion: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'version' => $this->version,
            'engine' => $this->engine,
            'engineVersion' => $this->engineVersion,
        ];
    }
}
