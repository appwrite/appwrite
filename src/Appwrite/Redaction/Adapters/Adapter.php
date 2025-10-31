<?php

namespace Appwrite\Redaction\Adapters;

use Appwrite\Redaction\Exceptions\Redaction;

interface Adapter
{
    /**
     * Redacts a given value and returns the redacted string.
     *
     * @throws Redaction on invalid input or adapter error
     */
    public function redact(string $value): string;
}

