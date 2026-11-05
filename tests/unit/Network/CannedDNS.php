<?php

namespace Tests\Unit\Network;

use Appwrite\Network\Validator\DNS;

/**
 * Answers from a fixed table instead of DNS, so a validator built on top of DNS records
 * can be checked without a resolver or a Swoole coroutine.
 *
 * The table is static because validators receive the class name and construct the DNS
 * validator themselves, the same way the proxy module does in production.
 */
final class CannedDNS extends DNS
{
    /**
     * @var array<string, list<string>> queried name => record values
     */
    public static array $answers = [];

    public function isValid(mixed $value): bool
    {
        $this->value = \is_string($value) ? $value : '';
        $this->records = self::$answers[$this->value] ?? [];
        $this->count = \count($this->records);

        return \in_array($this->target, $this->records, true);
    }
}
