<?php

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class ScryptModified extends Hash
{
    /**
     * Constructor to initialize with secure default options
     */
    public function __construct()
    {
        // Generate cryptographically secure random values
        $salt = random_bytes(16);
        $saltSeparator = random_bytes(16);
        $signerKey = random_bytes(32);

        $this->setOption('type', $this->getName());

        // Set default options with secure random values
        $this->setOption('salt', base64_encode($salt));
        $this->setOption('saltSeparator', base64_encode($saltSeparator));
        $this->setOption('signerKey', base64_encode($signerKey));
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        $options = $this->getOptions();

        if (! \is_string($options['signerKey'])) {
            throw new \InvalidArgumentException('Signer key must be a string');
        }

        $derivedKeyBytes = $this->generateDerivedKey($value);
        $signerKeyBytes = base64_decode($options['signerKey']);

        return base64_encode($this->hashKeys($signerKeyBytes, $derivedKeyBytes));
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->hash($value));
    }

    /**
     * Generate derived key using scrypt
     *
     * @throws \RuntimeException If scrypt extension is not installed
     */
    private function generateDerivedKey(string $value): string
    {
        if (! \function_exists('scrypt')) {
            throw new \RuntimeException('The scrypt extension is required. Please install php-scrypt.');
        }

        $options = $this->getOptions();

        if (! \is_string($options['salt']) || ! \is_string($options['saltSeparator'])) {
            throw new \InvalidArgumentException('Salt and salt separator must be strings');
        }

        $saltBytes = base64_decode($options['salt']);
        $saltSeparatorBytes = base64_decode($options['saltSeparator']);

        $value = mb_convert_encoding($value, 'UTF-8');
        $derivedKey = scrypt($value, $saltBytes . $saltSeparatorBytes, 16384, 8, 1, 64);
        if ($derivedKey === false) {
            throw new \RuntimeException('Failed to generate derived key using scrypt');
        }

        $result = hex2bin($derivedKey);
        if ($result === false) {
            throw new \RuntimeException('Failed to convert derived key from hex to binary');
        }

        return $result;
    }

    /**
     * Hash keys using AES-256-CTR
     *
     * @throws \RuntimeException If encryption fails
     */
    private function hashKeys(string $signerKeyBytes, string $derivedKeyBytes): string
    {
        $key = substr($derivedKeyBytes, 0, 32);
        $iv = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        $result = openssl_encrypt($signerKeyBytes, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($result === false) {
            throw new \RuntimeException('Failed to encrypt using AES-256-CTR');
        }

        return $result;
    }

    /**
     * Set salt value
     *
     * @param  string  $salt  Base64 encoded salt value
     *
     * @throws \InvalidArgumentException
     */
    public function setSalt(string $salt): static
    {
        if ($salt === '' || $salt === '0') {
            throw new \InvalidArgumentException('Salt cannot be empty');
        }

        if (! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $salt)) {
            throw new \InvalidArgumentException('Salt must be base64 encoded');
        }

        $this->setOption('salt', $salt);

        return $this;
    }

    /**
     * Set salt separator
     *
     * @param  string  $separator  Base64 encoded salt separator
     *
     * @throws \InvalidArgumentException
     */
    public function setSaltSeparator(string $separator): static
    {
        if (! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $separator)) {
            throw new \InvalidArgumentException('Salt separator must be base64 encoded');
        }

        $this->setOption('saltSeparator', $separator);

        return $this;
    }

    /**
     * Set signer key
     *
     * @param  string  $key  Base64 encoded signer key
     *
     * @throws \InvalidArgumentException
     */
    public function setSignerKey(string $key): static
    {
        if ($key === '' || $key === '0') {
            throw new \InvalidArgumentException('Signer key cannot be empty');
        }

        if (! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $key)) {
            throw new \InvalidArgumentException('Signer key must be base64 encoded');
        }

        $this->setOption('signerKey', $key);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'scryptMod';
    }
}
