<?php

namespace Utopia\Agents;

/**
 * Token usage, normalized across providers: $input counts every prompt token,
 * cached ones included, and $output counts every generated token, reasoning
 * included. The cache and reasoning counts are subsets of those two.
 */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $reasoning = 0,
    ) {
    }

    public function total(): int
    {
        return $this->input + $this->output;
    }

    public function add(self $usage): self
    {
        return new self(
            input: $this->input + $usage->input,
            output: $this->output + $usage->output,
            cacheRead: $this->cacheRead + $usage->cacheRead,
            cacheWrite: $this->cacheWrite + $usage->cacheWrite,
            reasoning: $this->reasoning + $usage->reasoning,
        );
    }
}
