<?php

namespace Tests\Unit\Migration;

use Appwrite\Auth\OAuth2\Secret as OAuth2Secret;
use Appwrite\Migration\Version\V24;
use Appwrite\OpenSSL\OpenSSL;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Document;
use Utopia\System\System;

class V24OAuthProvidersTest extends TestCase
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

    private function migrateProjectDocument(Document $document): Document
    {
        $migration = new V24();
        $method = new ReflectionMethod(V24::class, 'migrateDocument');
        $method->setAccessible(true);

        return $method->invoke($migration, $document);
    }

    public function testMigrationConvertsLegacyNestedEncryptedSecrets(): void
    {
        $plainSecret = 'github-client-secret';
        $document = new Document([
            '$collection' => 'projects',
            'oAuthProviders' => [
                'githubSecret' => $this->encryptLegacySecret($plainSecret),
                'googleSecret' => 'already-plain-secret',
            ],
        ]);

        $migrated = $this->migrateProjectDocument($document);
        $oAuthProviders = $migrated->getAttribute('oAuthProviders', []);

        $this->assertSame($plainSecret, $oAuthProviders['githubSecret']);
        $this->assertSame('already-plain-secret', $oAuthProviders['googleSecret']);
    }

    public function testMigrationIsIdempotent(): void
    {
        $plainSecret = 'discord-client-secret';
        $document = new Document([
            '$collection' => 'projects',
            'oAuthProviders' => [
                'discordSecret' => $this->encryptLegacySecret($plainSecret),
            ],
        ]);

        $firstPass = $this->migrateProjectDocument($document);
        $secondPass = $this->migrateProjectDocument($firstPass);

        $this->assertSame(
            $firstPass->getAttribute('oAuthProviders', []),
            $secondPass->getAttribute('oAuthProviders', [])
        );
        $this->assertSame($plainSecret, $secondPass->getAttribute('oAuthProviders', [])['discordSecret']);
    }

    public function testMigrationSkipsNonLegacyArraySecrets(): void
    {
        $document = new Document([
            '$collection' => 'projects',
            'oAuthProviders' => [
                'gitlabSecret' => ['clientSecret' => 'not-legacy-format'],
            ],
        ]);

        $migrated = $this->migrateProjectDocument($document);

        $this->assertSame(
            ['clientSecret' => 'not-legacy-format'],
            $migrated->getAttribute('oAuthProviders', [])['gitlabSecret']
        );
    }

    public function testNormalizeMatchesMigrationOutput(): void
    {
        $plainSecret = \json_encode(['clientSecret' => 'ms-secret', 'tenantID' => 'common']);
        $legacy = $this->encryptLegacySecret($plainSecret);

        $document = new Document([
            '$collection' => 'projects',
            'oAuthProviders' => [
                'microsoftSecret' => $legacy,
            ],
        ]);

        $migrated = $this->migrateProjectDocument($document);

        $this->assertSame($plainSecret, OAuth2Secret::normalize($legacy));
        $this->assertSame($plainSecret, $migrated->getAttribute('oAuthProviders', [])['microsoftSecret']);
    }
}
