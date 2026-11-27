<?php

namespace Utopia\Abuse\Challenge;

/**
 * A token a challenge issued: the signed value the client sends back with its
 * answer. A kind whose client needs more to answer extends it.
 */
readonly class Token
{
    public function __construct(public string $value)
    {
    }
}
