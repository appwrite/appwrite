<?php

namespace Utopia\Agents\Exception;

use Utopia\Agents\Exception;
use Utopia\Agents\Usage;

/**
 * The model's structured output is not valid JSON or does not match the schema.
 * $usage is what the run cost, summed over every step, since no Result reports it.
 */
class Output extends Exception
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        string $message,
        public readonly string $text = '',
        public readonly array $errors = [],
        public readonly Usage $usage = new Usage(),
    ) {
        parent::__construct($message);
    }
}
