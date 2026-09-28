<?php

declare(strict_types=1);

namespace Utopia\Platform;

/**
 * Enum metadata for whitelist-backed parameters.
 */
final readonly class Enum
{
    /**
     * @param  string|null  $name Generated enum name.
     * @param  array<string, string>|null  $map Mapping of whitelist values to generated enum case names.
     * @param  list<string>|null  $exclude Whitelist values to omit from generated enums.
     */
    public function __construct(
        public ?string $name = null,
        public ?array $map = null,
        public ?array $exclude = null,
    ) {}
}
