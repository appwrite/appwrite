<?php

declare(strict_types=1);

namespace Utopia\Auth;

use Utopia\Auth\Hashes\Argon2;

abstract class Proof
{
    public function __construct(protected Hash $hash = new Argon2()) {}

    /**
     * Set custom hash
     */
    public function setHash(Hash $hash): static
    {
        $this->hash = $hash;

        return $this;
    }

    /**
     * Get current hash
     */
    public function getHash(): Hash
    {
        return $this->hash;
    }

    /**
     * Generate a proof
     */
    abstract public function generate(): string;

    /**
     * Hash a proof
     */
    public function hash(string $proof): string
    {
        return $this->hash->hash($proof);
    }

    /**
     * Verify a proof
     */
    public function verify(string $proof, string $hash): bool
    {
        return $this->hash->verify($proof, $hash);
    }
}
