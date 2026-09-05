<?php

namespace Appwrite\Auth\OIDC;

/**
 * Verification profile for a provider's OpenID Connect ID tokens.
 */
class Profile
{
    /**
     * @param string[] $issuers Accepted `iss` claim values, matched exactly
     * @param bool $nonceRequired Reject tokens without a nonce claim. The nonce
     *     is the only binding between a token and the sign-in ceremony that
     *     requested it, so providers whose native SDKs support it should
     *     require it to prevent replay of harvested tokens.
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $issuers,
        public readonly string $jwksUrl,
        public readonly bool $nonceRequired = false,
    ) {
    }
}
