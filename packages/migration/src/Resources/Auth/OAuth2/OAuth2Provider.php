<?php

namespace Utopia\Migration\Resources\Auth\OAuth2;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * OAuth2 provider secrets are write-only and are not migrated.
 */
final class OAuth2Provider extends Resource
{
    private const TARGET_APP_ID = 'appId';
    private const TARGET_SECRET = 'secret';

    /**
     * Allow-list of readable provider fields that are safe to migrate, keyed by
     * provider. Each field declares where it lands on the destination:
     *  - target `appId`  -> the provider's `{key}Appid` attribute (one per provider)
     *  - target `secret` -> merged into the `{key}Secret` JSON blob, renamed via `key`
     *
     * Anything not listed here (clientSecret, Apple's p8File, etc.) is never copied,
     * so a secret field the server may add upstream cannot leak into a migration.
     *
     * @var array<string, array<string, array{target: string, key?: string}>>
     */
    public const PROVIDERS = [
        'amazon' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'apple' => [
            'serviceId' => ['target' => self::TARGET_APP_ID],
            'keyId' => ['target' => self::TARGET_SECRET, 'key' => 'keyID'],
            'teamId' => ['target' => self::TARGET_SECRET, 'key' => 'teamID'],
        ],
        'appwrite' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'auth0' => ['clientId' => ['target' => self::TARGET_APP_ID], 'endpoint' => ['target' => self::TARGET_SECRET]],
        'authentik' => ['clientId' => ['target' => self::TARGET_APP_ID], 'endpoint' => ['target' => self::TARGET_SECRET]],
        'autodesk' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'bitbucket' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'bitly' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'box' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'cloudflare' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'dailymotion' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'discord' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'disqus' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'dropbox' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'etsy' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'facebook' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'figma' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'fusionauth' => ['clientId' => ['target' => self::TARGET_APP_ID], 'endpoint' => ['target' => self::TARGET_SECRET]],
        'github' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'gitlab' => ['clientId' => ['target' => self::TARGET_APP_ID], 'endpoint' => ['target' => self::TARGET_SECRET]],
        'google' => ['clientId' => ['target' => self::TARGET_APP_ID], 'prompt' => ['target' => self::TARGET_SECRET]],
        'huggingface' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'kakao' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'keycloak' => [
            'clientId' => ['target' => self::TARGET_APP_ID],
            'endpoint' => ['target' => self::TARGET_SECRET, 'key' => 'keycloakDomain'],
            'realmName' => ['target' => self::TARGET_SECRET, 'key' => 'keycloakRealm'],
        ],
        'kick' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'linkedin' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'microsoft' => ['clientId' => ['target' => self::TARGET_APP_ID], 'tenant' => ['target' => self::TARGET_SECRET]],
        'notion' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'oidc' => [
            'clientId' => ['target' => self::TARGET_APP_ID],
            'wellKnownURL' => ['target' => self::TARGET_SECRET, 'key' => 'wellKnownEndpoint'],
            'authorizationURL' => ['target' => self::TARGET_SECRET, 'key' => 'authorizationEndpoint'],
            'tokenURL' => ['target' => self::TARGET_SECRET, 'key' => 'tokenEndpoint'],
            'userInfoURL' => ['target' => self::TARGET_SECRET, 'key' => 'userInfoEndpoint'],
        ],
        'okta' => [
            'clientId' => ['target' => self::TARGET_APP_ID],
            'domain' => ['target' => self::TARGET_SECRET, 'key' => 'oktaDomain'],
            'authorizationServerId' => ['target' => self::TARGET_SECRET],
        ],
        'paypal' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'paypalSandbox' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'podio' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'resend' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'salesforce' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'slack' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'spotify' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'stripe' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'tiktok' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'tradeshift' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'tradeshiftBox' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'twitch' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'webflow' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'wordpress' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'x' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'yahoo' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'yandex' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'zoho' => ['clientId' => ['target' => self::TARGET_APP_ID]],
        'zoom' => ['clientId' => ['target' => self::TARGET_APP_ID]],
    ];

    public function __construct(
        string $id,
        protected readonly string $providerKey,
        protected readonly bool $enabled = false,
        protected readonly array $settings = [],
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public static function getName(): string
    {
        return Resource::TYPE_OAUTH2_PROVIDER;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_AUTH;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function fromArray(string $providerKey, array $array): ?self
    {
        $allowed = self::PROVIDERS[$providerKey] ?? null;
        if ($allowed === null) {
            return null;
        }

        $settings = [];
        foreach (\array_keys($allowed) as $field) {
            if (\array_key_exists($field, $array)) {
                $settings[$field] = $array[$field];
            }
        }

        return new self(
            $array['id'],
            $providerKey,
            (bool) ($array['enabled'] ?? false),
            $settings,
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'providerKey' => $this->providerKey,
            'enabled' => $this->enabled,
            'settings' => $this->settings,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Value for the destination's `{key}Appid` attribute (clientId, or serviceId
     * for Apple). Null when unset, so callers can skip it without a separate
     * emptiness check.
     */
    public function getDestinationAppId(): ?string
    {
        foreach ($this->getDescriptor() as $field => $metadata) {
            if ($metadata['target'] !== self::TARGET_APP_ID) {
                continue;
            }

            $value = $this->settings[$field] ?? null;

            return self::isEmpty($value) ? null : (string) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDestinationSecretFields(): array
    {
        $fields = [];
        foreach ($this->getDescriptor() as $field => $metadata) {
            if ($metadata['target'] !== self::TARGET_SECRET || !\array_key_exists($field, $this->settings)) {
                continue;
            }

            $value = $this->settings[$field];
            if (self::isEmpty($value)) {
                continue;
            }

            $fields[$metadata['key'] ?? $field] = $value;
        }

        return $fields;
    }

    public function isConfigured(): bool
    {
        return $this->enabled || $this->getDestinationAppId() !== null;
    }

    /**
     * @return array<string, array{target: string, key?: string}>
     */
    private function getDescriptor(): array
    {
        return self::PROVIDERS[$this->providerKey] ?? [];
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
