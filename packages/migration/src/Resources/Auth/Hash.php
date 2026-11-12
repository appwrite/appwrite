<?php

namespace Utopia\Migration\Resources\Auth;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Helper class for hashing.
 */
class Hash extends Resource
{
    public const ALGORITHM_SCRYPT_MODIFIED = 'scryptModified';

    public const ALGORITHM_BCRYPT = 'bcrypt';

    public const ALGORITHM_MD5 = 'md5';

    public const ALGORITHM_ARGON2 = 'argon2';

    public const ALGORITHM_SHA256 = 'sha256';

    public const ALGORITHM_PHPASS = 'phpass';

    public const ALGORITHM_SCRYPT = 'scrypt';

    public const ALGORITHM_PLAINTEXT = 'plainText';

    public function __construct(
        private readonly string $hash,
        private readonly string $salt = '',
        private readonly string $algorithm = self::ALGORITHM_SHA256,
        private readonly string $separator = '',
        private readonly string $signingKey = '',
        private readonly int $passwordCpu = 0,
        private readonly int $passwordMemory = 0,
        private readonly int $passwordParallel = 0,
        private readonly int $passwordLength = 0
    ) {
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['hash'] ?? '',
            $array['salt'] ?? '',
            $array['algorithm'] ?? self::ALGORITHM_SHA256,
            $array['separator'] ?? '',
            $array['signingKey'] ?? '',
            $array['passwordCpu'] ?? 0,
            $array['passwordMemory'] ?? 0,
            $array['passwordParallel'] ?? 0,
            $array['passwordLength'] ?? 0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'hash' => $this->hash,
            'salt' => $this->salt,
            'algorithm' => $this->algorithm,
            'separator' => $this->separator,
            'signingKey' => $this->signingKey,
            'passwordCpu' => $this->passwordCpu,
            'passwordMemory' => $this->passwordMemory,
            'passwordParallel' => $this->passwordParallel,
            'passwordLength' => $this->passwordLength,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_HASH;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    /**
     * Get Hash
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Get Salt
     */
    public function getSalt(): string
    {
        return $this->salt;
    }

    /**
     * Get Algorithm
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Get Separator
     */
    public function getSeparator(): string
    {
        return $this->separator;
    }

    /**
     * Get Signing Key
     */
    public function getSigningKey(): string
    {
        return $this->signingKey;
    }

    /**
     * Get Password CPU
     */
    public function getPasswordCpu(): int
    {
        return $this->passwordCpu;
    }

    /**
     * Get Password Memory
     */
    public function getPasswordMemory(): int
    {
        return $this->passwordMemory;
    }

    /**
     * Get Password Parallel
     */
    public function getPasswordParallel(): int
    {
        return $this->passwordParallel;
    }

    /**
     * Get Password Length
     */
    public function getPasswordLength(): int
    {
        return $this->passwordLength;
    }
}
