<?php

namespace Appwrite\Auth\SAML;

use Utopia\Config\Config;

/**
 * Helpers for separating SAML providers from OAuth2 providers.
 *
 * Both live in the `oAuthProviders` config so they share project credential
 * storage and the console provider listing, but they are different protocols
 * and must not be interchangeable on the wire. Every OAuth2 route builds its
 * provider whitelist from `oauth2Providers()`, so a SAML provider id is never
 * accepted by an OAuth2 endpoint, and vice versa.
 */
class Provider
{
    /**
     * Config key holding the provider id for every SAML provider.
     */
    public const string ID = 'saml';

    /**
     * Providers without an explicit protocol predate this split and are OAuth2.
     */
    private const string DEFAULT_PROTOCOL = 'oauth2';

    /**
     * Provider ids that speak OAuth2, for OAuth2 route whitelists.
     *
     * @return array<int, string>
     */
    public static function oauth2Providers(): array
    {
        return \array_keys(\array_filter(
            Config::getParam('oAuthProviders') ?? [],
            fn ($node) => ($node['protocol'] ?? self::DEFAULT_PROTOCOL) === self::DEFAULT_PROTOCOL
        ));
    }

    /**
     * Provider ids that speak SAML, for the SDK enum exclusion lists.
     *
     * @return array<int, string>
     */
    public static function samlProviders(): array
    {
        return \array_keys(\array_filter(
            Config::getParam('oAuthProviders') ?? [],
            fn ($node) => ($node['protocol'] ?? self::DEFAULT_PROTOCOL) === self::ID
        ));
    }

    /**
     * @param string $provider
     *
     * @return bool
     */
    public static function isSaml(string $provider): bool
    {
        $node = Config::getParam('oAuthProviders')[$provider] ?? null;

        if ($node === null) {
            return false;
        }

        return ($node['protocol'] ?? self::DEFAULT_PROTOCOL) === self::ID;
    }
}
