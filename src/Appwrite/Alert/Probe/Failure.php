<?php

namespace Appwrite\Alert\Probe;

/**
 * Ends a check early with the result it failed with, and the last code the
 * target sent before it did.
 */
class Failure extends \RuntimeException
{
    public function __construct(
        public readonly Result $result,
        public readonly ?int $reply = null,
        string $message = '',
    ) {
        parent::__construct($message === '' ? $result->phrase() : $message);
    }
}
