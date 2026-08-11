<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\SAML\Ticket;

/**
 * Adapter that lets a completed SAML sign-in reuse the OAuth2 session
 * pipeline.
 *
 * SAML does not fit the OAuth2 shape: there is no client secret, no
 * authorization redirect we can build from a stored endpoint alone, and no
 * code-for-token exchange. The protocol work therefore happens in the SAML
 * routes and in Appwrite\Auth\SAML; by the time this adapter is constructed
 * the assertion has already been received, signature-verified and validated.
 *
 * What is left is the half of the flow SAML and OAuth2 genuinely share:
 * looking up or creating the user, linking the identity, and issuing a session
 * or token. That logic lives in the OAuth2 redirect route, so this adapter
 * presents the validated identity through the OAuth2 provider interface and
 * lets that route run unmodified.
 *
 * The `$code` here is not an OAuth2 authorization code. It is a single-use
 * opaque key into the identity record the Assertion Consumer Service stored,
 * and it never leaves Appwrite: the identity provider never sees it.
 */
class Saml extends OAuth2
{
    /**
     * Ticket store used to redeem the exchange code. Injected by the redirect
     * route rather than the constructor, because the OAuth2 provider signature
     * is fixed at ($appId, $appSecret, $callback).
     *
     * @var Ticket|null
     */
    private static ?Ticket $ticket = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $identity = null;

    /**
     * @param Ticket $ticket
     *
     * @return void
     */
    public static function setTicket(Ticket $ticket): void
    {
        self::$ticket = $ticket;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'saml';
    }

    /**
     * SAML sign-in never starts here. The flow-initiation route builds an
     * AuthnRequest and redirects to the identity provider itself, because a
     * SAML request is a signed XML document rather than a URL that can be
     * assembled from stored credentials.
     *
     * @return string
     */
    public function getLoginURL(): string
    {
        throw new Exception('SAML sign-in must be started from the SAML endpoint, not the OAuth2 endpoint.', 400);
    }

    /**
     * Resolve the single-use code the Assertion Consumer Service issued.
     *
     * This is the deliberate seam: there is no network call and no token
     * exchange. The assertion was already validated before the code existed.
     *
     * @param string $code
     *
     * @return array<string, mixed>
     */
    protected function getTokens(string $code): array
    {
        // Resolved on the first call, which getAccessToken() makes. Later calls
        // arrive with an empty string, because nothing about the code is
        // persisted, and reuse what was already resolved.
        if ($this->identity !== null) {
            return $this->identity;
        }

        if ($code === '') {
            throw new Exception('SAML identity has not been resolved for this request.', 401);
        }

        if (self::$ticket === null) {
            throw new Exception('SAML identity store is unavailable.', 500);
        }

        $identity = self::$ticket->consume(Ticket::IDENTITIES, $code);

        if ($identity === null) {
            throw new Exception('This SAML sign-in has already been used or has expired. Please try signing in again.', 401);
        }

        $this->identity = $identity;

        return $this->identity;
    }

    /**
     * SAML has no refresh mechanism: re-authentication means a new assertion.
     *
     * @param string $refreshToken
     *
     * @return array<string, mixed>
     */
    public function refreshTokens(string $refreshToken): array
    {
        return [];
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        return $this->getTokens($accessToken)['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return $this->getTokens($accessToken)['email'] ?? '';
    }

    /**
     * The identity provider authenticated the user and signed the assertion
     * saying so, which is a stronger statement than a self-asserted OAuth2
     * `email_verified` claim.
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return !empty($this->getTokens($accessToken)['email']);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        return $this->getTokens($accessToken)['name'] ?? '';
    }

    /**
     * The pipeline calls getAccessToken($code) first, then passes the result to
     * the identity getters, and finally stores it on the identity and session
     * documents as the provider access token.
     *
     * SAML has no provider token to store, and the exchange code is a
     * credential that must not be persisted, so this resolves the identity into
     * memory and returns an empty string. The getters below fall back to that
     * resolved identity, which is why they work with an empty argument.
     *
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $this->getTokens($code);

        return '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getRefreshToken(string $code): string
    {
        return '';
    }

    /**
     * @param string $code
     *
     * @return int
     */
    public function getAccessTokenExpiry(string $code): int
    {
        return 0;
    }
}
