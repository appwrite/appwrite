<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * Model reasoning. Providers that sign their reasoning need the signature sent
 * back unchanged on the next turn; redacted reasoning carries only that
 * signature.
 */
final readonly class Reasoning implements Part
{
    public function __construct(
        public string $text,
        public ?string $signature = null,
        public bool $redacted = false,
    ) {
    }
}
