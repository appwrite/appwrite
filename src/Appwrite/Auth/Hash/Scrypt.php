<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * Scrypt accepted options:
 * string? salt; auto-generated if empty
 * int costCpu
 * int costMemory
 * int costParallel
 * int length
 *
 * Reference: https://github.com/DomBlack/php-scrypt/blob/master/scrypt.php#L112-L116
*/
class Scrypt extends Hash
{
    /**
     * @param  string  $password Input password to hash
     * @return string hash
     */
    public function hash(string $password): string
    {
        $options = $this->getOptions();

        return \scrypt($password, $options['salt'], $options['costCpu'], $options['costMemory'], $options['costParallel'], $options['length']);
    }

    /**
     * @param  string  $password Input password to validate
     * @param  string  $hash Hash to verify password against
     * @return bool true if password matches hash
     */
    public function verify(string $password, string $hash): bool
    {
        return $hash === $this->hash($password);
    }

    /**
     * Get default options for specific hashing algo
     *
     * @return array options named array
     */
    public function getDefaultOptions(): array
    {
        return ['costCpu' => 8, 'costMemory' => 14, 'costParallel' => 1, 'length' => 64];
    }
}
