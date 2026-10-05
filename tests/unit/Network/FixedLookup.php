<?php

namespace Tests\Unit\Network;

use Utopia\DNS\Lookup;

/**
 * Answers from a fixed table instead of DNS.
 */
final readonly class FixedLookup implements Lookup
{
    /**
     * @param array<string, list<string>> $answers hostname => addresses
     */
    public function __construct(
        private array $answers = [],
    ) {
    }

    public function addresses(string $hostname): array
    {
        return $this->answers[$hostname] ?? [];
    }
}
