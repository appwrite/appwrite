<?php

namespace Appwrite\Auth\OIDC;

/**
 * Verification profile for a provider's OpenID Connect ID tokens.
 */
class Profile
{
    /**
     * @param string[] $issuers Accepted `iss` claim values, matched exactly
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $issuers,
        public readonly string $jwksUrl,
    ) {
    }
}
