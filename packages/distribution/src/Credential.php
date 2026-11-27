<?php

declare(strict_types=1);

namespace Utopia\Distribution;

abstract readonly class Credential
{
    protected const string REDACTED = '[redacted]';

    private const string PATH = 'file://';

    private const string PEM = '-----BEGIN';

    protected static function isPem(#[\SensitiveParameter] string $key): bool
    {
        return !str_starts_with($key, self::PATH) && str_contains($key, self::PEM);
    }
}
