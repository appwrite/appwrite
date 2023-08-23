<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * SHA accepted options:
 * string? version. Allowed:
 *  - Version 1: sha1
 *  - Version 2: sha224, sha256, sha384, sha512/224, sha512/256, sha512
 *  - Version 3: sha3-224, sha3-256, sha3-384, sha3-512
 *
 * Reference: https://www.php.net/manual/en/function.hash-algos.php
*/
class Sha extends Hash
{
    /**
     * @param string $password Input password to hash
     *
     * @return string hash
     */
    public function hash(string $password): string
    {
        $algo = $this->getOptions()['version'];

        return \hash($algo, $password);
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
        return [ 'version' => 'sha3-512' ];
    }
}
