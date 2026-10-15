<?php

namespace Utopia\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;

class OAuth2ProviderTest extends TestCase
{
    public function testFromArrayAppwrite(): void
    {
        $provider = OAuth2Provider::fromArray('appwrite', [
            'id' => 'appwrite',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('appwrite', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayHuggingFace(): void
    {
        $provider = OAuth2Provider::fromArray('huggingface', [
            'id' => 'huggingface',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('huggingface', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayResend(): void
    {
        $provider = OAuth2Provider::fromArray('resend', [
            'id' => 'resend',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('resend', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayCloudflare(): void
    {
        $provider = OAuth2Provider::fromArray('cloudflare', [
            'id' => 'cloudflare',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('cloudflare', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayTikTok(): void
    {
        $provider = OAuth2Provider::fromArray('tiktok', [
            'id' => 'tiktok',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('tiktok', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayKakao(): void
    {
        $provider = OAuth2Provider::fromArray('kakao', [
            'id' => 'kakao',
            'enabled' => true,
            'clientId' => 'client-123',
            'clientSecret' => 'super-secret',
        ]);

        $this->assertNotNull($provider);
        $this->assertEquals('kakao', $provider->getProviderKey());
        $this->assertTrue($provider->getEnabled());
        $this->assertEquals(['clientId' => 'client-123'], $provider->getSettings());
        $this->assertEquals('client-123', $provider->getDestinationAppId());
        $this->assertEquals([], $provider->getDestinationSecretFields());
        $this->assertTrue($provider->isConfigured());
    }

    public function testFromArrayNeverCopiesSecrets(): void
    {
        foreach (\array_keys(OAuth2Provider::PROVIDERS) as $providerKey) {
            $provider = OAuth2Provider::fromArray($providerKey, [
                'id' => $providerKey,
                'enabled' => false,
                'clientId' => 'client-123',
                'clientSecret' => 'super-secret',
                'p8File' => 'p8-contents',
            ]);

            $this->assertNotNull($provider);
            $this->assertArrayNotHasKey('clientSecret', $provider->getSettings(), $providerKey);
            $this->assertArrayNotHasKey('p8File', $provider->getSettings(), $providerKey);
        }
    }

    public function testFromArrayUnknownProvider(): void
    {
        $this->assertNull(OAuth2Provider::fromArray('unknown', [
            'id' => 'unknown',
            'enabled' => true,
            'clientId' => 'client-123',
        ]));
    }
}
