<?php

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Mock;
use Appwrite\Auth\OAuth2\Secret as OAuth2Secret;
use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\System\System;

class SecretTest extends TestCase
{
    private function encryptLegacySecret(string $secret): array
    {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1');
        $method = OpenSSL::CIPHER_AES_128_GCM;
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength($method));
        $tag = null;

        return [
            'data' => OpenSSL::encrypt($secret, $method, $key, 0, $iv, $tag),
            'method' => $method,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag ?? ''),
            'version' => '1',
        ];
    }

    public function testNormalizeReturnsStringUnchanged(): void
    {
        $secret = 'plain-oauth-secret';

        $this->assertSame($secret, OAuth2Secret::normalize($secret));
    }

    public function testNormalizeDecryptsLegacyEncryptedArray(): void
    {
        $secret = 'legacy-oauth-secret';
        $legacy = $this->encryptLegacySecret($secret);

        $this->assertSame($secret, OAuth2Secret::normalize($legacy));
    }

    public function testNormalizeThrowsForInvalidFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid OAuth2 provider secret format.');

        OAuth2Secret::normalize(['unexpected' => 'value']);
    }

    public function testFromProjectReturnsNormalizedSecret(): void
    {
        $secret = 'github-secret';
        $project = new Document([
            'oAuthProviders' => [
                'githubSecret' => $secret,
            ],
        ]);

        $this->assertSame($secret, OAuth2Secret::fromProject($project, 'github'));
    }

    public function testFromProjectDecryptsLegacyEncryptedSecret(): void
    {
        $secret = 'microsoft-secret-json';
        $project = new Document([
            'oAuthProviders' => [
                'microsoftSecret' => $this->encryptLegacySecret($secret),
            ],
        ]);

        $this->assertSame($secret, OAuth2Secret::fromProject($project, 'microsoft'));
    }

    public function testFromProjectReturnsEmptyStringWhenMissing(): void
    {
        $project = new Document([
            'oAuthProviders' => [],
        ]);

        $this->assertSame('', OAuth2Secret::fromProject($project, 'google'));
    }

    public function testOAuth2ConstructorNeverReceivesArray(): void
    {
        $secret = 'mock-secret';
        $normalized = OAuth2Secret::normalize($this->encryptLegacySecret($secret));

        $this->assertIsString($normalized);

        $oauth2 = new Mock('mock-app-id', $normalized, 'https://example.com/callback');

        $this->assertSame('mock', $oauth2->getName());
    }
}
