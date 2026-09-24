<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Reference;

final readonly class ResolutionContext
{
    /** @param list<string> $trail */
    public function __construct(
        public array $trail = [],
        public ?string $baseUri = null,
    ) {
    }
}
