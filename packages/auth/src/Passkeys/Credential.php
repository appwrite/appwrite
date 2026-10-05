<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

/**
 * A verified passkey: persist the record, and index the identifier to find it at sign-in.
 */
readonly class Credential
{
    /**
     * @param string $identifier lookup key derived from the credential ID
     * @param array<mixed> $record opaque verification material to store and pass back at sign-in
     * @param bool $backedUp whether the passkey is synced to a cloud account rather than bound to one device
     */
    public function __construct(
        public string $identifier,
        public array $record,
        public bool $backedUp,
    ) {
    }
}
