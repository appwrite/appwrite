<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * Model reasoning. Providers that sign their reasoning need the signature sent
 * back unchanged on the next turn; redacted reasoning carries only that
 * signature. Providers that name their reasoning items, such as OpenAI, need
 * the id sent back with it.
 */
final readonly class Reasoning implements Part
{
    public function __construct(
        public string $text,
        public ?string $signature = null,
        public bool $redacted = false,
        public ?string $id = null,
    ) {
    }
}
