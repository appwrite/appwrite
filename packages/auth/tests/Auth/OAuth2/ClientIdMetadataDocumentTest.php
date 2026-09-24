<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\ClientIdentifierUrl;
use Utopia\Auth\OAuth2\ClientIdMetadataDocument;
use Utopia\Auth\OAuth2\InvalidClientMetadataException;

final class ClientIdMetadataDocumentTest extends TestCase
{
    private const string CLIENT_ID = 'https://client.example/metadata';

    public function testParsesPublicClientDocument(): void
    {
        $metadata = [
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Example MCP Client',
            'redirect_uris' => ['https://client.example/callback', 'myapp:/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'extension_property' => ['enabled' => true],
            'nullable_extension' => null,
        ];

        $document = ClientIdMetadataDocument::fromJson(
            $this->clientId(),
            json_encode($metadata, \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(self::CLIENT_ID, $document->clientId()->toString());
        $this->assertSame('none', $document->tokenEndpointAuthMethod());
        $this->assertSame(['authorization_code', 'refresh_token'], $document->grantTypes());
        $this->assertSame(['code'], $document->responseTypes());
        $this->assertSame($metadata['redirect_uris'], $document->redirectUris()->toArray());
        $this->assertSame(['enabled' => true], $document->get('extension_property'));
        $this->assertNull($document->get('nullable_extension', 'fallback'));
        $this->assertSame($metadata, $document->toArray());
    }

    public function testAppliesRegistrationDefaults(): void
    {
        $document = ClientIdMetadataDocument::fromArray($this->clientId(), [
            'client_id' => self::CLIENT_ID,
            'token_endpoint_auth_method' => 'none',
        ]);

        $this->assertSame(['authorization_code'], $document->grantTypes());
        $this->assertSame(['code'], $document->responseTypes());
        $this->assertSame([], $document->redirectUris()->toArray());
        $this->assertSame('fallback', $document->get('unknown', 'fallback'));
    }

    public function testAcceptsPublicKeyAuthentication(): void
    {
        $document = ClientIdMetadataDocument::fromArray($this->clientId(), [
            'client_id' => self::CLIENT_ID,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => [
                'keys' => [[
                    'kty' => 'RSA',
                    'n' => 'public-modulus',
                    'e' => 'AQAB',
                ]],
            ],
        ]);

        $this->assertSame('private_key_jwt', $document->tokenEndpointAuthMethod());
    }

    #[DataProvider('invalidJsonProvider')]
    public function testRejectsInvalidJson(string $json): void
    {
        $this->expectException(InvalidClientMetadataException::class);

        ClientIdMetadataDocument::fromJson($this->clientId(), $json);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('invalidMetadataProvider')]
    public function testRejectsInvalidMetadata(array $metadata): void
    {
        $this->expectException(InvalidClientMetadataException::class);

        ClientIdMetadataDocument::fromArray($this->clientId(), $metadata);
    }

    /**
     * @return \Iterator<string, array{json: string}>
     */
    public static function invalidJsonProvider(): \Iterator
    {
        yield 'malformed' => ['json' => '{'];
        yield 'list root' => ['json' => '[]'];
        yield 'scalar root' => ['json' => 'true'];
    }

    /**
     * @return \Iterator<string, array{metadata: array<string, mixed>}>
     */
    public static function invalidMetadataProvider(): \Iterator
    {
        $valid = [
            'client_id' => self::CLIENT_ID,
            'token_endpoint_auth_method' => 'none',
        ];

        yield 'missing client id' => ['metadata' => ['token_endpoint_auth_method' => 'none']];
        yield 'mismatched client id' => ['metadata' => array_replace($valid, ['client_id' => 'https://other.example/metadata'])];
        yield 'missing auth method' => ['metadata' => ['client_id' => self::CLIENT_ID]];
        yield 'empty auth method' => ['metadata' => array_replace($valid, ['token_endpoint_auth_method' => ''])];
        yield 'client secret basic' => ['metadata' => array_replace($valid, ['token_endpoint_auth_method' => 'client_secret_basic'])];
        yield 'future client secret method' => ['metadata' => array_replace($valid, ['token_endpoint_auth_method' => 'client_secret_custom'])];
        yield 'client secret' => ['metadata' => $valid + ['client_secret' => 'secret']];
        yield 'client secret expiry' => ['metadata' => $valid + ['client_secret_expires_at' => 0]];
        yield 'grant types not a list' => ['metadata' => $valid + ['grant_types' => 'authorization_code']];
        yield 'empty grant type' => ['metadata' => $valid + ['grant_types' => ['']]];
        yield 'response types not strings' => ['metadata' => $valid + ['response_types' => [1]]];
        yield 'redirect URI with fragment' => ['metadata' => $valid + ['redirect_uris' => ['https://client.example/callback#fragment']]];
        yield 'relative redirect URI' => ['metadata' => $valid + ['redirect_uris' => ['/callback']]];
        yield 'contacts not a list' => ['metadata' => $valid + ['contacts' => 'owner@example.com']];
        yield 'client name not a string' => ['metadata' => $valid + ['client_name' => ['Example']]];
        yield 'client name null' => ['metadata' => $valid + ['client_name' => null]];
        yield 'jwks null' => ['metadata' => $valid + ['jwks' => null]];
        yield 'malformed jwks' => ['metadata' => $valid + ['jwks' => ['keys' => 'not-a-list']]];
        yield 'jwks and jwks uri' => ['metadata' => $valid + [
            'jwks' => ['keys' => []],
            'jwks_uri' => 'https://client.example/jwks.json',
        ]];
        yield 'symmetric JWK' => ['metadata' => $valid + ['jwks' => ['keys' => [['kty' => 'oct', 'k' => 'secret']]]]];
        yield 'private RSA JWK' => ['metadata' => $valid + ['jwks' => ['keys' => [['kty' => 'RSA', 'n' => 'n', 'e' => 'AQAB', 'd' => 'private']]]]];
    }

    private function clientId(): ClientIdentifierUrl
    {
        return ClientIdentifierUrl::fromString(self::CLIENT_ID);
    }
}
