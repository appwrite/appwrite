<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Request
{
    public function __construct(
        public readonly string $subject,
        public readonly string $data = '',
        public readonly ?Headers $headers = null,
    ) {
        if ($subject === '' || preg_match('/[\s\x00*>]/', $subject)
            || str_starts_with($subject, '.') || str_ends_with($subject, '.')
            || str_contains($subject, '..')) {
            throw new \InvalidArgumentException('Invalid request subject');
        }
    }
}
