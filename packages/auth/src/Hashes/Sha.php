<?php

declare(strict_types=1);

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class Sha extends Hash
{
    public const SHA1 = 'sha1';

    public const SHA224 = 'sha224';

    public const SHA256 = 'sha256';

    public const SHA384 = 'sha384';

    public const SHA512 = 'sha512';

    public const SHA3_224 = 'sha3-224';

    public const SHA3_256 = 'sha3-256';

    public const SHA3_384 = 'sha3-384';

    public const SHA3_512 = 'sha3-512';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setOption('version', 'sha256');
    }

    /**
     * Valid SHA versions
     */
    private const array VALID_VERSIONS = [
        'sha1',
        'sha224',
        'sha256',
        'sha384',
        'sha512',
        'sha3-224',
        'sha3-256',
        'sha3-384',
        'sha3-512',
    ];

    /**
     * Set SHA version
     *
     * @param  string  $version  SHA version to use
     *
     * @throws \InvalidArgumentException
     */
    public function setVersion(string $version): static
    {
        if (! \in_array($version, self::VALID_VERSIONS, true)) {
            throw new \InvalidArgumentException('Invalid SHA version. Valid versions are: ' . implode(', ', self::VALID_VERSIONS));
        }

        $this->setOption('version', $version);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        $version = $this->getOption('version');
        if (! \is_string($version)) {
            throw new \RuntimeException('SHA version must be a string');
        }

        return hash($version, $value);
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
        return 'sha';
    }
}
