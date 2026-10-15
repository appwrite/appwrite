<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

/**
 * The relying party passkeys are bound to: its ID (a domain) and the exact web origins allowed to run ceremonies.
 */
readonly class RelyingParty
{
    /**
     * @param array<string> $origins
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $origins,
    ) {
    }

    /**
     * Stable digest of the configuration, for invalidating ceremonies started under a different one.
     */
    public function getFingerprint(): string
    {
        $origins = $this->origins;
        \sort($origins);

        return \hash('sha256', $this->id . "\n" . \implode("\n", $origins));
    }
}
