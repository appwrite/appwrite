<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\OpenSSL\OpenSSL;
use Utopia\System\System;

/**
 * Proof Key for Code Exchange (PKCE) support for OAuth2 providers.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636
 *
 * OAuth2 adapters are reconstructed statelessly on the callback request, so the
 * code verifier generated while building the login URL is gone by the time the
 * authorization code is exchanged. Providers therefore stash the verifier in the
 * `state` payload with withPKCEState() and restore it from parseState() with
 * restorePKCEState().
 *
 * The verifier is encrypted before it goes into the state, because the state is
 * echoed back through the provider and is visible in the redirect URL, browser
 * history and referrer logs. A verifier that can be read there gives an attacker
 * everything needed to redeem an intercepted authorization code, which is exactly
 * what PKCE exists to prevent.
 */
trait PKCE
{
    protected const PKCE_STATE_KEY = '_pkce';

    private string $pkceVerifier = '';

    /**
     * RFC 7636 §4.1 requires 43-128 characters from the unreserved set.
     * base64url(random_bytes(64)) is 86 characters.
     */
    protected function getPKCEVerifier(): string
    {
        if ($this->pkceVerifier === '') {
            $this->pkceVerifier = $this->base64UrlEncode(\random_bytes(64));
        }

        return $this->pkceVerifier;
    }

    /**
     * RFC 7636 §4.2 defines the S256 challenge as BASE64URL(SHA256(ASCII(verifier))).
     * Sending the bare verifier here would reduce the exchange to the `plain`
     * method, so the challenge must always be hashed.
     */
    protected function getPKCEChallenge(): string
    {
        return $this->base64UrlEncode(\hash('sha256', $this->getPKCEVerifier(), true));
    }

    /**
     * Add the encrypted verifier to the state sent to the provider.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    protected function withPKCEState(array $state): array
    {
        $state[self::PKCE_STATE_KEY] = $this->encryptPKCEVerifier($this->getPKCEVerifier());

        return $state;
    }

    /**
     * Restore the verifier from the state returned by the provider and strip the
     * PKCE entry so callers only see their own state.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    protected function restorePKCEState(array $parsed): array
    {
        $pkce = $parsed[self::PKCE_STATE_KEY] ?? null;

        if (\is_array($pkce)) {
            $this->pkceVerifier = $this->decryptPKCEVerifier($pkce);
        } elseif (\is_string($pkce)) {
            // Authorizations started before the verifier was encrypted carry it as
            // a plain string. Kept so logins already in flight during an upgrade
            // still complete; safe to remove in a later release.
            $this->pkceVerifier = $pkce;
        }

        unset($parsed[self::PKCE_STATE_KEY]);

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function encryptPKCEVerifier(string $verifier): array
    {
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;

        $data = OpenSSL::encrypt($verifier, OpenSSL::CIPHER_AES_128_GCM, $this->getPKCEStateKey(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($data === false || $tag === null) {
            throw new \Exception('Failed to encrypt PKCE verifier.');
        }

        return [
            'data' => $this->base64UrlEncode($data),
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decryptPKCEVerifier(array $payload): string
    {
        $data = $payload['data'] ?? '';
        $iv = $payload['iv'] ?? '';
        $tag = $payload['tag'] ?? '';

        // The payload is rebuilt from the state query parameter, so the values are
        // attacker controlled and may not be strings at all.
        if (!\is_string($data) || !\is_string($iv) || !\is_string($tag)) {
            return '';
        }

        if ($data === '' || $iv === '' || $tag === '') {
            return '';
        }

        // hex2bin() emits a PHP warning on odd-length or non-hex input, which a
        // malformed state would otherwise let anyone trigger at will.
        if (!$this->isHex($iv) || !$this->isHex($tag)) {
            return '';
        }

        $decodedData = $this->base64UrlDecode($data);
        $decodedIv = \hex2bin($iv);
        $decodedTag = \hex2bin($tag);

        if ($decodedData === false || $decodedIv === false || $decodedTag === false) {
            return '';
        }

        return OpenSSL::decrypt(
            $decodedData,
            OpenSSL::CIPHER_AES_128_GCM,
            $this->getPKCEStateKey(),
            OPENSSL_RAW_DATA,
            $decodedIv,
            $decodedTag
        ) ?: '';
    }

    private function isHex(string $value): bool
    {
        return \strlen($value) % 2 === 0 && \ctype_xdigit($value);
    }

    private function getPKCEStateKey(): string
    {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        if ($key === '') {
            throw new \Exception($this->getName() . ' OAuth2 requires _APP_OPENSSL_KEY_V1 to encrypt PKCE state.');
        }

        return $key;
    }

    protected function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string|false
    {
        return \base64_decode(\strtr($value, '-_', '+/'), true);
    }
}
