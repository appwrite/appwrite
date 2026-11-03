<?php

namespace Utopia\Agents\Exception;

use Utopia\Agents\Exception;

/**
 * The model's structured output is not valid JSON or does not match the schema.
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
    ) {
        parent::__construct($message);
    }
}
