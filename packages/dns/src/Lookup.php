<?php

declare(strict_types=1);

namespace Utopia\DNS;

/**
 * Finds the addresses a hostname points to.
 */
interface Lookup
{
    /**
     * @return list<string> Every A and AAAA address of the hostname, or none when it cannot be resolved
     */
    public function addresses(string $hostname): array;
}
