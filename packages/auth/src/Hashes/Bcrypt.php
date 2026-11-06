<?php

declare(strict_types=1);

namespace Utopia\Auth\Hashes;

use Utopia\Auth\Hash;

class Bcrypt extends Hash
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setOption('type', $this->getName());
        $this->setOption('cost', 8);
    }

    /**
     * {@inheritdoc}
     */
    public function hash(string $value): string
    {
        return password_hash($value, PASSWORD_BCRYPT, $this->getOptions());
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
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
        if ($cost < 4 || $cost > 31) {
            throw new \InvalidArgumentException('Cost must be between 4 and 31');
        }

        $this->setOption('cost', $cost);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'bcrypt';
    }
}
