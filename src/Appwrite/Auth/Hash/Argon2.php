<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * Argon2 accepted options:
 * int threads
 * int time_cost
 * int memory_cost
 *
 * Refference: https://www.php.net/manual/en/function.password-hash.php#example-983
*/
class Argon2 extends Hash
{
    /**
     * @param string $password Input password to hash
     *
     * @return string hash
     */
    public function hash(string $password): string
    {
        return \password_hash($password, PASSWORD_ARGON2ID, $this->getOptions());
    }

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     *
     * @return boolean true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * Get default options for specific hashing algo
     *
     * @return mixed options named array
     */
    public function getDefaultOptions(): mixed
    {
        return ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3];
    }
}
