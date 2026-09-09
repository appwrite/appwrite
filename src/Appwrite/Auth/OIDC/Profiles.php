<?php

namespace Appwrite\Auth\OIDC;

use Utopia\Database\Document;

/**
 * Registry of providers whose ID tokens Appwrite can verify natively.
 */
class Profiles
{
    /**
     * Returns an empty document for providers without native ID token support.
     *
     * Attributes: `issuers` are accepted `iss` claim values, matched exactly;
     * `jwksUrl` publishes the signing keys; `nonceRequired` rejects tokens
     * without a nonce claim. The nonce is the only binding between a token and
     * the sign-in ceremony that requested it, so providers whose native SDKs
     * support one should require it to prevent replay of harvested tokens.
     */
    public static function get(string $provider): Document
    {
        return new Document(match ($provider) {
            'google' => [
                '$id' => 'google',
                // Google issued tokens without the scheme historically; both remain valid.
                'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
                'jwksUrl' => 'https://www.googleapis.com/oauth2/v3/certs',
                'nonceRequired' => false,
            ],
            'apple' => [
                '$id' => 'apple',
                'issuers' => ['https://appleid.apple.com'],
                'jwksUrl' => 'https://appleid.apple.com/auth/keys',
                // ASAuthorizationController always supports request.nonce, and a
                // nonce-less Apple token is replayable for its full lifetime
                'nonceRequired' => true,
            ],
            'mock' => [
                '$id' => 'mock',
                'issuers' => ['https://localhost/v1/mock'],
                'jwksUrl' => 'http://localhost/v1/mock/tests/general/oauth2/jwks',
                'nonceRequired' => false,
            ],
            default => [],
        });
    }
}
