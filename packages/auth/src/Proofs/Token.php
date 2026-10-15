<?php

namespace Utopia\Auth\Proofs;

use Utopia\Auth\Proof;

class Token extends Proof
{
    protected int $length;

    /**
     * @throws \Exception
     */
    public function __construct(int $length = 256)
    {
        parent::__construct();

        if ($length <= 0) {
            throw new \Exception('Token length must be greater than 0');
        }

        $this->length = $length;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(): string
    {
        $bytesLength = max(1, (int) ceil($this->length / 2));
        $token = bin2hex(random_bytes($bytesLength));

        return substr($token, 0, $this->length);
    }

    /**
     * Get the token length
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Set the token length
     *
     *
     * @throws \Exception
     */
    public function setLength(int $length): static
    {
        if ($length <= 0) {
            throw new \Exception('Token length must be greater than 0');
        }

        $this->length = $length;

        return $this;
    }
}
