<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * Bcrypt accepted options:
 * int cost
 * string? salt; auto-generated if empty
 *
 * Reference: https://www.php.net/manual/en/password.constants.php
*/
class Bcrypt extends Hash
{
    /**
     * @param  string  $password Input password to hash
     * @return string hash
     */
    public function hash(string $password): string
    {
        return \password_hash($password, PASSWORD_BCRYPT, $this->getOptions());
    }

    /**
     * @param  string  $password Input password to validate
     * @param  string  $hash Hash to verify password against
     * @return bool true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    public function getDefaultOptions(): array
    {
        return ['cost' => 8];
    }
}
