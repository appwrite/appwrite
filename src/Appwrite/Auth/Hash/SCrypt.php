<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * SCrypt accepted options:
 * string? salt; auto-generated if empty
 * int cost_cpu
 * int cost_memory
 * int cost_parallel
 * int length
 * 
 * Refference: https://github.com/DomBlack/php-scrypt/blob/master/scrypt.php#L112-L116
*/
class SCrypt extends Hash
{
    /**
     * @param string $password Input password to hash
     * 
     * @return string hash
     */
    public function hash(string $password): string {
        $options = $this->getOptions();

        return \scrypt($password, $options['salt'] ?? null, $options['cost_cpu'], $options['cost_memory'], $options['cost_parallel'], $options['length']);
    }

    /**
     * @param string $password Input password to validate
     * @param string $hash Hash to verify password against
     * 
     * @return boolean true if password matches hash
     */
    public function verify(string $password, string $hash): bool {
        return $hash === $this->hash($password);
    }

    /**
     * Get default options for specific hashing algo
     * 
     * @return mixed options named array
     */
    public function getDefaultOptions(): mixed {
        return [ 'cost_cpu' => 8, 'cost_memory' => 14, 'cost_parallel' => 1, 'length' => 64 ];
    }
}