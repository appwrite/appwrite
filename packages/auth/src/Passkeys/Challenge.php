<?php

declare(strict_types=1);

namespace Utopia\Auth\Passkeys;

/**
 * A started ceremony: options for the browser, and opaque state to persist until the credential comes back.
 */
readonly class Challenge
{
    /**
     * @param array<mixed> $options pass to PublicKeyCredential.parseCreationOptionsFromJSON() or parseRequestOptionsFromJSON()
     */
    public function __construct(
        public array $options,
        public string $state,
    ) {
    }
}
