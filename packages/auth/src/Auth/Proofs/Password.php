<?php

namespace Utopia\Auth\Proofs;

use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\MD5;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\ScryptModified;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proof;

class Password extends Proof
{
    public const ARGON2 = 'argon2';

    public const BCRYPT = 'bcrypt';

    public const SCRYPT = 'scrypt';

    public const SCRYPT_MODIFIED = 'scryptMod';

    public const SHA = 'sha';

    public const MD5 = 'md5';

    public const PHPASS = 'phpass';

    /**
     * @var array<string, Hash>
     */
    protected array $hashes = [];

    protected int $defaultLength = 16;

    protected string $defaultCharset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';

    /**
     * @param  array<string, Hash>  $hashes
     *
     * @throws \Exception
     */
    public function __construct(array $hashes = [])
    {
        parent::__construct();

        // Initialize default hashes if no hashes were provided
        if ($hashes === []) {
            $hashes = [
                self::ARGON2 => new Argon2(),
                self::BCRYPT => new Bcrypt(),
                self::SCRYPT => new Scrypt(),
                self::SCRYPT_MODIFIED => new ScryptModified(),
                self::SHA => new Sha(),
                self::MD5 => new MD5(),
                self::PHPASS => new PHPass(),
            ];
        }

        $this->hashes = $hashes;

        // Keep the active hash aligned with the registry so generate()/hash()
        // use a registered algorithm (Argon2 by default, otherwise the first
        // registered one) and removeHash()'s current-hash guard can match it.
        $this->hash = $this->hashes[self::ARGON2] ?? array_values($this->hashes)[0];
    }

    /**
     * Add a new hashing hash
     */
    public function addHash(string $name, Hash $hash): static
    {
        $this->hashes[$name] = $hash;

        return $this;
    }

    /**
     * Remove a hashing hash
     *
     *
     * @throws \Exception
     */
    public function removeHash(string $name): static
    {
        if (! isset($this->hashes[$name])) {
            throw new \Exception("Hash '{$name}' not found");
        }

        if ($this->hash === $this->hashes[$name]) {
            throw new \Exception('Cannot remove current hash');
        }

        unset($this->hashes[$name]);

        return $this;
    }

    /**
     * Get a specific hashing hash by name
     *
     *
     * @throws \Exception
     */
    public function getHashByName(string $name): Hash
    {
        if (! isset($this->hashes[$name])) {
            throw new \Exception("Hash '{$name}' not found");
        }

        return $this->hashes[$name];
    }

    /**
     * Set password generation length
     *
     *
     * @throws \Exception
     */
    public function setLength(int $length): static
    {
        if ($length < 8) {
            throw new \Exception('Password length must be at least 8 characters');
        }
        $this->defaultLength = $length;

        return $this;
    }

    /**
     * Set password generation charset
     *
     *
     * @throws \Exception
     */
    public function setCharset(string $charset): static
    {
        if (\strlen($charset) < 10) {
            throw new \Exception('Password charset must contain at least 10 characters');
        }
        $this->defaultCharset = $charset;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(): string
    {
        $password = '';
        $max = \strlen($this->defaultCharset) - 1;

        if ($max < 0) {
            throw new \Exception('Password charset is empty');
        }

        for ($i = 0; $i < $this->defaultLength; $i++) {
            $password .= $this->defaultCharset[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Create a hash instance by type
     *
     * @param  string  $type  One of the supported hash types (ARGON2, BCRYPT, SCRYPT, SCRYPT_MODIFIED, SHA, MD5, PHPASS)
     * @param  array<string, mixed>  $options  Optional parameters for hash configuration
     *
     * @throws \Exception
     */
    public static function createHash(string $type, array $options = []): Hash
    {
        $hash = match ($type) {
            self::ARGON2 => new Argon2(),
            self::BCRYPT => new Bcrypt(),
            self::SCRYPT => new Scrypt(),
            self::SCRYPT_MODIFIED => new ScryptModified(),
            self::SHA => new Sha(),
            self::MD5 => new MD5(),
            self::PHPASS => new PHPass(),
            default => throw new \Exception("Unsupported hash type: {$type}")
        };

        $hash->setOptions($options);

        return $hash;
    }
}
