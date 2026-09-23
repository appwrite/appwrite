<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser\Schema;

use Utopia\OpenAPI\Version;

/**
 * The JSON Schema rules an OpenAPI version permits. Version differences inside
 * schema reading are carried as data here rather than as branches on Version.
 */
final readonly class Dialect
{
    public function __construct(
        public bool $booleanSchemas,
        public bool $typeArrays,
        public bool $constKeyword,
    ) {}

    public static function for(Version $version): self
    {
        return match ($version) {
            Version::V2, Version::V3_0 => new self(false, false, false),
            Version::V3_1 => new self(true, true, true),
        };
    }
}
