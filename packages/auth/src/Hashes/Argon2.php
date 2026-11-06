<?php

declare(strict_types=1);

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class Argon2 extends Hash
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setOption('type', $this->getName());
        $this->setOption('memory_cost', 65536);
        $this->setOption('time_cost', 4);
        $this->setOption('threads', 3);
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        return password_hash($value, PASSWORD_ARGON2ID, $this->getOptions());
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
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
        $this->setOption('memory_cost', $cost);

        return $this;
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
        $this->setOption('time_cost', $cost);

        return $this;
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
        $this->setOption('threads', $threads);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'argon2';
    }
}
