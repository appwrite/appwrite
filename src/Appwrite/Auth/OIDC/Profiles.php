<?php

namespace Appwrite\Auth\OIDC;

/**
 * Registry of providers whose ID tokens Appwrite can verify natively.
 */
class Profiles
{
    public static function get(string $provider): ?Profile
    {
        return match ($provider) {
            'google' => new Profile(
                provider: 'google',
                // Google issued tokens without the scheme historically; both remain valid.
                issuers: ['https://accounts.google.com', 'accounts.google.com'],
                jwksUrl: 'https://www.googleapis.com/oauth2/v3/certs',
            ),
            'apple' => new Profile(
                provider: 'apple',
                issuers: ['https://appleid.apple.com'],
                jwksUrl: 'https://appleid.apple.com/auth/keys',
                // ASAuthorizationController always supports request.nonce, and a
                // nonce-less Apple token is replayable for its full lifetime
                nonceRequired: true,
            ),
            'mock' => new Profile(
                provider: 'mock',
                issuers: ['https://localhost/v1/mock'],
                jwksUrl: 'http://localhost/v1/mock/tests/general/oauth2/jwks',
            ),
            default => null,
        };
    }
}
