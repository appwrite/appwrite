<?php

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class Scrypt extends Hash
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setOption('type', $this->getName());
        $this->setOption('costCpu', 8);
        $this->setOption('costMemory', 14);
        $this->setOption('costParallel', 1);
        $this->setOption('length', 64);
        $this->setOption('salt', bin2hex(random_bytes(16)));
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        if (! \function_exists('scrypt')) {
            throw new \RuntimeException('The scrypt extension is required. Please install php-scrypt.');
        }

        $salt = $this->getOption('salt');
        $costCpu = $this->getOption('costCpu');
        $costMemory = $this->getOption('costMemory');
        $costParallel = $this->getOption('costParallel');
        $length = $this->getOption('length');

        if (! \is_string($salt)) {
            throw new \InvalidArgumentException('Salt must be a string');
        }

        if (! \is_int($costCpu) || ! \is_int($costMemory) || ! \is_int($costParallel) || ! \is_int($length)) {
            throw new \InvalidArgumentException('Scrypt cost and length options must be integers');
        }

        $hash = scrypt(
            $value,
            $salt,
            $costCpu,
            $costMemory,
            $costParallel,
            $length,
        );

        if ($hash === false) {
            throw new \RuntimeException('Failed to hash using scrypt');
        }

        return $hash;
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->hash($value));
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
        if ($cost <= 1 || ($cost & ($cost - 1)) !== 0) {
            throw new \InvalidArgumentException('CPU cost must be > 1 and a power of 2');
        }

        $this->setOption('costCpu', $cost);

        return $this;
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
        if ($cost < 1) {
            throw new \InvalidArgumentException('Memory cost must be >= 1');
        }

        $this->setOption('costMemory', $cost);

        return $this;
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
        if ($cost < 1) {
            throw new \InvalidArgumentException('Parallel cost must be >= 1');
        }

        $this->setOption('costParallel', $cost);

        return $this;
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
        if ($length < 16) {
            throw new \InvalidArgumentException('Length must be >= 16 bytes');
        }

        $this->setOption('length', $length);

        return $this;
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
        if ($salt === '' || $salt === '0') {
            throw new \InvalidArgumentException('Salt cannot be empty');
        }

        $this->setOption('salt', $salt);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'scrypt';
    }
}
