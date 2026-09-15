<?php

namespace Appwrite\Auth\Pwned;

use Appwrite\Auth\Pwned;

/**
 * Reports a fixed set of passwords as breached, without leaving the process.
 *
 * Lets the test suite exercise the policy without depending on a breach
 * service. Selecting it on a production server is refused, because it would
 * quietly report every real password as safe.
 *
 * DSN: `mock://localhost`, no details are read.
 */
class Mock extends Pwned
{
    /**
     * The passwords this adapter reports as breached.
     */
    public const BREACHED = [
        'pwned-fixture-common',
        'pwned-fixture-uncommon',
        'pwned-fixture-rare',
    ];

    public function getName(): string
    {
        return 'mock';
    }

    public function isPwned(string $password): bool
    {
        return \in_array($password, self::BREACHED, true);
    }
}
