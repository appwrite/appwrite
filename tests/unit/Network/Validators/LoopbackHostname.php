<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicHostname;

class LoopbackHostname extends PublicHostname
{
    public static function resolve(string $hostname): array
    {
        return match ($hostname) {
            'loopback.invalid', 'loopback.invalid.' => ['127.0.0.1', '::1'],
            'loopback6.invalid' => ['::1'],
            default => [],
        };
    }
}
