<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned;

/**
 * Reports a fixed set of passwords as breached, without leaving the process.
 *
 * Lets the test suite exercise the policy without depending on a breach
 * service. Selecting it on a production server is refused, because it would
 * quietly report every real password as safe.
 *
 * DSN: `mock://localhost`, no details are read.
 */
class Mock extends PasswordPwned
{
    /**
     * The passwords this validator reports as breached.
     */
    public const BREACHED = [
        'pwned-fixture-common',
        'pwned-fixture-uncommon',
        'pwned-fixture-rare',
    ];

    protected function isPwned(string $password): bool
    {
        return \in_array($password, self::BREACHED, true);
    }
}
