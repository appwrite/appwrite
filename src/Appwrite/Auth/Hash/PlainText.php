<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * Argon2 accepted options:
 * none
 * 
 * Refference: None. Simple plain text stored.
*/
class Argon2 extends Hash
{
    /**
     * @param string $password Input password to hash
     * 
     * @return string hash
     */
    public function hash(string $password): string {
        return $password;
    }

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     * 
     * @return boolean true if password matches hash
     */
    public function verify(string $password, string $hash): bool {
        return $password === $hash;
    }

    /**
     * Get default options for specific hashing algo
     * 
     * @return mixed options named array
     */
    public function getDefaultOptions(): mixed {
        return [];
    }
}