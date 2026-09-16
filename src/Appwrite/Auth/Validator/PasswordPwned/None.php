<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned;

/**
 * Reports every password as safe, without asking anyone.
 *
 * For a server that must not, or cannot, reach a breach service, such as an
 * air-gapped installation. Projects can still switch the policy on, but it
 * protects nothing until the server is pointed at a real service, and every
 * checked user reads as clean.
 *
 * DSN: `none://localhost`, no details are read.
 */
class None extends PasswordPwned
{
    protected function isPwned(string $password): bool
    {
        return false;
    }
}
