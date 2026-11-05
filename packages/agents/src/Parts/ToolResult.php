<?php

namespace Utopia\Agents\Parts;

use Utopia\Agents\Part;

/**
 * The outcome of a tool call, sent back to the model.
 */
final readonly class ToolResult implements Part
{
    public function __construct(
        public string $id,
        public string $name,
        public mixed $output,
        public bool $error = false,
    ) {
    }

    /**
     * The output as the text providers expect: strings pass through, anything
     * else is JSON encoded.
     */
    public function text(): string
    {
        if (\is_string($this->output)) {
            return $this->output;
        }

        return json_encode($this->output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }
}
