<?php

declare(strict_types=1);

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class MD5 extends Hash
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setOption('type', $this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        return md5($value);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->hash($value));
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'md5';
    }
}
