<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * A request from the model to run a tool.
 */
final readonly class ToolCall implements Part
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $signature  Opaque provider token that must accompany the call on the next turn
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public ?string $signature = null,
    ) {
    }
}
