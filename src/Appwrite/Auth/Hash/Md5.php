<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * MD5 does not accept any options.
 *
 * Reference: https://www.php.net/manual/en/function.md5.php
*/
class Md5 extends Hash
{
    /**
     * @param string $password Input password to hash
     *
     * @return string hash
     */
    public function hash(string $password): string
    {
        return \md5($password);
    }

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     *
     * @return boolean true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        return $this->hash($password) === $hash;
    }

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    public function getDefaultOptions(): array
    {
        return [];
    }
}
