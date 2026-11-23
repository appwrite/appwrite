<?php

namespace Utopia\Abuse\Challenge;

/**
 * What a challenge hands the client: the signed token to send back with the
 * answer. A kind that needs more (a puzzle's nonce, a captcha's image) extends it.
 */
readonly class Prompt
{
    public function __construct(public string $token)
    {
    }
}
