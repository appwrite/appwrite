<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicHostname;

class FixedHostname extends PublicHostname
{
    public static function resolve(string $hostname): array
    {
        return match ($hostname) {
            'smtp.example.com', 'example.com' => ['93.184.215.14'],
            'backup.example.com' => ['93.184.215.15'],
            'internal.example.com', 'rebind.example.com' => ['10.0.0.1'],
            'ipv6.example.com' => ['2606:4700:4700::1111'],
            default => [],
        };
    }
}
