<?php

declare(strict_types=1);

namespace Utopia\Auth\Proofs;

use Utopia\Auth\Proof;

class Code extends Proof
{
    protected int $length;

    /**
     * @throws \Exception
     */
    public function __construct(int $length = 6)
    {
        parent::__construct();

        if ($length <= 0) {
            throw new \Exception('Code length must be greater than 0');
        }

        $this->length = $length;
    }

    /**
     * Get the code length
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Set the code length
     *
     *
     * @throws \Exception
     */
    public function setLength(int $length): static
    {
        if ($length <= 0) {
            throw new \Exception('Code length must be greater than 0');
        }

        $this->length = $length;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(): string
    {
        $value = '';

        for ($i = 0; $i < $this->length; $i++) {
            $value .= random_int(0, 9);
        }

        return $value;
    }
}
