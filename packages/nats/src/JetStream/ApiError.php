<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class ApiError
{
    public function __construct(
        public readonly int $code,
        public readonly int $errCode,
        public readonly string $description,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            code: $data['code'] ?? 0,
            errCode: $data['err_code'] ?? 0,
            description: $data['description'] ?? 'Unknown error',
        );
    }
}
