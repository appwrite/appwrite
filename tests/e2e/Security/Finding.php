<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

final readonly class Finding
{
    public const string ERROR = 'ERROR';
    public const string WARNING = 'WARNING';

    public function __construct(
        public string $attack,
        public string $method,
        public string $path,
        public string $probe,
        public string $detail,
        public string $severity = self::ERROR,
    ) {
    }

    public function key(): string
    {
        return $this->attack . "\0" . $this->method . "\0" . $this->path . "\0" . $this->probe;
    }

    /**
     * @return array{attack: string, method: string, path: string, probe: string, severity: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'attack' => $this->attack,
            'method' => $this->method,
            'path' => $this->path,
            'probe' => $this->probe,
            'severity' => $this->severity,
            'detail' => $this->detail,
        ];
    }
}
