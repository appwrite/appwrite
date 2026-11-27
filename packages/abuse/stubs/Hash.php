<?php

// Utopia\Auth\Hash and the password hashes of utopia-php/auth are registered by the utopia PHP
// extension (crates/extension in appwrite/appwrite). This file only declares them for static analysis
// and editors; it is never loaded.
// A copy of packages/auth/stubs/Hash.php: PHPStan for packages/abuse may only read files of this
// package, and runs where the utopia extension is not loaded.

namespace Utopia\Auth {
    abstract class Hash
    {
        /**
         * @var array<string, mixed> Hash-specific options
         */
        protected array $options = [];
        /**
         * Set hashing options
         *
         * @param  string  $key  The option key to set
         * @param  mixed  $value  The value to set for the option
         */
        public function setOption(string $key, mixed $value): static
        {
        }
        /**
         * Set multiple hashing options at once
         *
         * @param  array<string, mixed>  $options  Array of options to set
         */
        public function setOptions(array $options): static
        {
        }
        /**
         * Get a specific option value
         *
         * @param  string  $key  The option key to retrieve
         * @param  mixed  $default  Default value if option doesn't exist
         * @return mixed The option value or default if not found
         */
        public function getOption(string $key, mixed $default = null): mixed
        {
        }
        /**
         * Get hashing options
         *
         * @return array<string, mixed> Hash-specific options
         */
        public function getOptions(): array
        {
        }
        /**
         * Hash a value
         */
        abstract public function hash(string $value): string;
        /**
         * Verify a value against a hash
         */
        abstract public function verify(string $value, string $hash): bool;
        /**
         * Get the name of the hash algorithm
         */
        abstract public function getName(): string;
    }
}

namespace Utopia\Auth\Hashes {
    use Utopia\Auth\Hash;

    class Argon2 extends Hash
    {
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * Set memory cost
         *
         * @param  int  $cost  Memory cost in KiB
         *
         * @throws \InvalidArgumentException
         */
        public function setMemoryCost(int $cost): static
        {
        }
        /**
         * Set time cost
         *
         * @param  int  $cost  Number of iterations
         *
         * @throws \InvalidArgumentException
         */
        public function setTimeCost(int $cost): static
        {
        }
        /**
         * Set number of threads
         *
         * @param  int  $threads  Number of threads to use
         *
         * @throws \InvalidArgumentException
         */
        public function setThreads(int $threads): static
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class Bcrypt extends Hash
    {
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * Set cost parameter
         *
         * @param  int  $cost  Cost parameter between 4 and 31
         *
         * @throws \InvalidArgumentException
         */
        public function setCost(int $cost): static
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class MD5 extends Hash
    {
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class PHPass extends Hash
    {
        /**
         * Alphabet used in itoa64 conversions.
         */
        protected string $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * Get random bytes
         */
        protected function getRandomBytes(int $count): string
        {
        }
        /**
         * Encode in base64
         */
        protected function encode64(string $input, int $count): string
        {
        }
        /**
         * Generate salt for private key
         */
        private function gensaltPrivate(string $input): string
        {
        }
        /**
         * Generate salt for Blowfish
         */
        private function gensaltBlowfish(string $input): string
        {
        }
        /**
         * Crypt private
         */
        private function cryptPrivate(string $password, string $setting): string
        {
        }
        /**
         * Set iteration count (log2)
         *
         * @param  int  $count  Iteration count (log2) between 4 and 31
         *
         * @throws \InvalidArgumentException
         */
        public function setIterationCount(int $count): PHPass
        {
        }
        /**
         * Set portable hashes mode
         *
         * @param  bool  $portable  Whether to use portable hashes
         */
        public function setPortableHashes(bool $portable): PHPass
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class Plaintext extends Hash
    {
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class Scrypt extends Hash
    {
        /**
         * Constructor
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * Set CPU cost parameter
         *
         * @param  int  $cost  CPU cost parameter N. Must be larger than 1 and a power of 2
         *
         * @throws \InvalidArgumentException
         */
        public function setCpuCost(int $cost): self
        {
        }
        /**
         * Set memory cost parameter
         *
         * @param  int  $cost  Memory cost parameter r
         *
         * @throws \InvalidArgumentException
         */
        public function setMemoryCost(int $cost): static
        {
        }
        /**
         * Set parallelization parameter
         *
         * @param  int  $cost  Parallelization parameter p
         *
         * @throws \InvalidArgumentException
         */
        public function setParallelCost(int $cost): static
        {
        }
        /**
         * Set output length
         *
         * @param  int  $length  Desired output length in bytes
         *
         * @throws \InvalidArgumentException
         */
        public function setLength(int $length): static
        {
        }
        /**
         * Set salt value
         *
         * @param  string  $salt  Salt value for the hash
         *
         * @throws \InvalidArgumentException
         */
        public function setSalt(string $salt): static
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
    class ScryptModified extends Hash
    {
        /**
         * Constructor to initialize with secure default options
         */
        public function __construct()
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * Generate derived key using scrypt
         *
         * @throws \RuntimeException If scrypt extension is not installed
         */
        private function generateDerivedKey(string $value): string
        {
        }
        /**
         * Hash keys using AES-256-CTR
         *
         * @throws \RuntimeException If encryption fails
         */
        private function hashKeys(string $signerKeyBytes, string $derivedKeyBytes): string
        {
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
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
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
        }
        /**
         * Valid SHA versions
         */
        private const array VALID_VERSIONS = ['sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'];
        /**
         * Set SHA version
         *
         * @param  string  $version  SHA version to use
         *
         * @throws \InvalidArgumentException
         */
        public function setVersion(string $version): static
        {
        }
        /**
         * {@inheritdoc}
         */
        public function hash(string $value): string
        {
        }
        /**
         * {@inheritdoc}
         */
        public function verify(string $value, string $hash): bool
        {
        }
        /**
         * {@inheritdoc}
         */
        public function getName(): string
        {
        }
    }
}
