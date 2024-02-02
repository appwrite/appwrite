<?php

namespace Appwrite\Auth\Hash;

use Appwrite\Auth\Hash;

/*
 * This is Scrypt hash with some additional steps added by Google.
 *
 * string salt
 * string saltSeparator
 * strin signerKey
 *
 * Reference: https://github.com/DomBlack/php-scrypt/blob/master/scrypt.php#L112-L116
*/
class Scryptmodified extends Hash
{
    /**
     * @param string $password Input password to hash
     *
     * @return string hash
     */
    public function hash(string $password): string
    {
        $options = $this->getOptions();

        $derivedKeyBytes = $this->generateDerivedKey($password);
        $signerKeyBytes = \base64_decode($options['signerKey']);

        $hashedPassword = $this->hashKeys($signerKeyBytes, $derivedKeyBytes);

        return \base64_encode($hashedPassword);
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
        return [ ];
    }

    private function generateDerivedKey(string $password)
    {
        $options = $this->getOptions();

        $saltBytes = \base64_decode($options['salt']);
        $saltSeparatorBytes = \base64_decode($options['saltSeparator']);

        $password = mb_convert_encoding($password, 'UTF-8');
        $derivedKey = \scrypt($password, $saltBytes . $saltSeparatorBytes, 16384, 8, 1, 64);
        $derivedKey = \hex2bin($derivedKey);

        return $derivedKey;
    }

    private function hashKeys($signerKeyBytes, $derivedKeyBytes): string
    {
        $key = \substr($derivedKeyBytes, 0, 32);

        $iv = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        $hash = \openssl_encrypt($signerKeyBytes, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);

        return $hash;
    }
}
