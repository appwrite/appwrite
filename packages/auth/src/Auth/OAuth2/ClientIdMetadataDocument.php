<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Validated OAuth Client ID Metadata Document.
 *
 * Fetching, response-size enforcement, caching, SSRF protection, and
 * authorization-server capability policy belong to the consuming server.
 */
class ClientIdMetadataDocument
{
    /**
     * Standard metadata properties whose values must be JSON strings.
     * Unknown extension properties remain allowed and are preserved as-is.
     */
    private const array STRING_METADATA_PROPERTIES = [
        'client_name',
        'client_uri',
        'logo_uri',
        'policy_uri',
        'tos_uri',
        'jwks_uri',
        'scope',
        'software_id',
        'software_version',
    ];

    /**
     * JWK members that reveal secret key material:
     *
     * - `d` is an RSA private exponent or an EC/OKP private key.
     * - `p`, `q`, `dp`, `dq`, `qi`, and `oth` are private RSA factors and
     *   Chinese Remainder Theorem optimization parameters.
     * - `k` is the value of a symmetric key.
     *
     * CIDM documents may publish public keys, but must never publish these
     * private or symmetric components.
     */
    private const array PRIVATE_JWK_PARAMETERS = [
        'd',
        'dp',
        'dq',
        'k',
        'oth',
        'p',
        'q',
        'qi',
    ];

    /**
     * @param array<string, mixed> $metadata
     * @param list<non-empty-string> $grantTypes
     * @param list<non-empty-string> $responseTypes
     */
    private function __construct(
        private readonly ClientIdentifierUrl $clientId,
        private readonly array $metadata,
        private readonly string $tokenEndpointAuthMethod,
        private readonly array $grantTypes,
        private readonly array $responseTypes,
        private readonly RedirectUris $redirectUris,
    ) {}

    /**
     * Parse a JSON Client ID Metadata Document.
     *
     * @throws InvalidClientMetadataException
     */
    public static function fromJson(ClientIdentifierUrl $clientId, string $json): self
    {
        try {
            $decoded = json_decode($json, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidClientMetadataException('Client ID Metadata Document is not valid JSON.');
        }

        if (!$decoded instanceof \stdClass) {
            throw new InvalidClientMetadataException('Client ID Metadata Document must be a JSON object.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = self::normalizeJsonValue($decoded);

        return self::fromArray($clientId, $metadata);
    }

    /**
     * Validate an already decoded Client ID Metadata Document.
     *
     * Unknown metadata is preserved so extension specifications can be used
     * without requiring a package release.
     *
     * @param array<string, mixed> $metadata
     * @throws InvalidClientMetadataException
     */
    public static function fromArray(ClientIdentifierUrl $clientId, array $metadata): self
    {
        if (($metadata['client_id'] ?? null) !== $clientId->toString()) {
            throw new InvalidClientMetadataException('client_id must exactly match the Client Identifier URL.');
        }

        foreach (['client_secret', 'client_secret_expires_at'] as $property) {
            if (\array_key_exists($property, $metadata)) {
                throw new InvalidClientMetadataException("Client ID Metadata Documents must not contain {$property}.");
            }
        }

        // RFC 7591 defaults an omitted method to client_secret_basic. Since a
        // metadata document cannot establish a shared secret, clients need to
        // opt into a compatible method such as none or private_key_jwt.
        $tokenEndpointAuthMethod = $metadata['token_endpoint_auth_method'] ?? null;
        if (!\is_string($tokenEndpointAuthMethod) || $tokenEndpointAuthMethod === '') {
            throw new InvalidClientMetadataException('token_endpoint_auth_method must be explicitly declared.');
        }

        // All client_secret_* methods require a pre-established shared secret,
        // which a publicly fetched metadata document cannot securely provide.
        if (str_starts_with($tokenEndpointAuthMethod, 'client_secret_')) {
            throw new InvalidClientMetadataException('token_endpoint_auth_method must not use a shared symmetric secret.');
        }

        $grantTypes = self::stringList($metadata, 'grant_types', ['authorization_code']);
        $responseTypes = self::stringList($metadata, 'response_types', ['code']);
        $redirectUris = self::stringList($metadata, 'redirect_uris', []);

        foreach ($redirectUris as $redirectUri) {
            self::validateRedirectUri($redirectUri);
        }

        self::stringList($metadata, 'contacts', []);
        $postLogoutRedirectUris = self::stringList($metadata, 'post_logout_redirect_uris', []);
        foreach ($postLogoutRedirectUris as $redirectUri) {
            self::validateRedirectUri($redirectUri);
        }

        foreach (self::STRING_METADATA_PROPERTIES as $property) {
            if (\array_key_exists($property, $metadata) && !\is_string($metadata[$property])) {
                throw new InvalidClientMetadataException("{$property} must be a string.");
            }
        }

        if (\array_key_exists('jwks', $metadata) && \array_key_exists('jwks_uri', $metadata)) {
            throw new InvalidClientMetadataException('jwks and jwks_uri must not both be present.');
        }

        if (\array_key_exists('jwks', $metadata)) {
            self::validateJwks($metadata['jwks']);
        }

        return new self(
            $clientId,
            $metadata,
            $tokenEndpointAuthMethod,
            $grantTypes,
            $responseTypes,
            RedirectUris::from($redirectUris),
        );
    }

    public function clientId(): ClientIdentifierUrl
    {
        return $this->clientId;
    }

    public function tokenEndpointAuthMethod(): string
    {
        return $this->tokenEndpointAuthMethod;
    }

    /**
     * @return list<non-empty-string>
     */
    public function grantTypes(): array
    {
        return $this->grantTypes;
    }

    /**
     * @return list<non-empty-string>
     */
    public function responseTypes(): array
    {
        return $this->responseTypes;
    }

    public function redirectUris(): RedirectUris
    {
        return $this->redirectUris;
    }

    public function get(string $property, mixed $default = null): mixed
    {
        return \array_key_exists($property, $this->metadata)
            ? $this->metadata[$property]
            : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<non-empty-string> $default
     * @return list<non-empty-string>
     */
    private static function stringList(array $metadata, string $property, array $default): array
    {
        if (!\array_key_exists($property, $metadata)) {
            return $default;
        }

        $values = $metadata[$property];
        if (!\is_array($values) || !array_is_list($values)) {
            throw new InvalidClientMetadataException("{$property} must be a list of strings.");
        }

        foreach ($values as $value) {
            if (!\is_string($value) || $value === '') {
                throw new InvalidClientMetadataException("{$property} must contain non-empty strings.");
            }
        }

        /** @var list<non-empty-string> $values */
        return $values;
    }

    private static function validateRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);

        if (!\is_array($parts) || empty($parts['scheme']) || isset($parts['fragment'])) {
            throw new InvalidClientMetadataException('redirect URIs must be absolute URIs without fragments.');
        }
    }

    private static function validateJwks(mixed $jwks): void
    {
        if (!\is_array($jwks) || !isset($jwks['keys']) || !\is_array($jwks['keys']) || !array_is_list($jwks['keys'])) {
            throw new InvalidClientMetadataException('jwks must be a JSON Web Key Set object.');
        }

        foreach ($jwks['keys'] as $jwk) {
            if (!\is_array($jwk) || array_is_list($jwk)) {
                throw new InvalidClientMetadataException('jwks must contain JSON Web Key objects.');
            }

            foreach (self::PRIVATE_JWK_PARAMETERS as $parameter) {
                if (\array_key_exists($parameter, $jwk)) {
                    throw new InvalidClientMetadataException('jwks must not contain private or symmetric key material.');
                }
            }
        }
    }

    private static function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $entry) {
                $normalized[$key] = self::normalizeJsonValue($entry);
            }

            return $normalized;
        }

        if (\is_array($value)) {
            return array_map(self::normalizeJsonValue(...), $value);
        }

        return $value;
    }
}
