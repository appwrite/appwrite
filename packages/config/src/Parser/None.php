<?php

declare(strict_types=1);

namespace Utopia\Config\Parser;

use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser;

class None extends Parser
{
    /**
     * @param \ReflectionClass<covariant object>|null $reflection
     * @return array<string, mixed>
     */
    public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array
    {
        if (!\is_array($contents)) {
            throw new Parse('Contents must be an array');
        }

        return $contents;
    }
}
