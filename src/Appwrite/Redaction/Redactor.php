<?php

namespace Appwrite\Redaction;

use Appwrite\Redaction\Adapters\Adapter;
use Appwrite\Redaction\Exceptions\Redaction as RedactionException;

final readonly class Redactor
{
    public function __construct(
        private Adapter $adapter
    )
    {
    }

    /**
     * @throws RedactionException
     */
    public function redact(string $value): RedactedValue
    {
        $masked = $this->adapter->redact($value);
        return new RedactedValue($value, $masked, $this->adapter);
    }

    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }
}
