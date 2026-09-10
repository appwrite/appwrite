<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class OAuthProvidersTest extends TestCase
{
    /**
     * Every provider opted into native ID token sign-in must describe how to
     * verify its tokens; the verifier reads these keys without a schema.
     */
    public function testIdTokenProfilesAreComplete(): void
    {
        $profiles = \array_filter(\array_column(Config::getParam('oAuthProviders'), 'idToken', 'name'));

        $this->assertNotEmpty($profiles);

        foreach ($profiles as $provider => $profile) {
            $this->assertIsArray($profile, $provider);
            $this->assertNotEmpty($profile['issuers'] ?? [], $provider);
            $this->assertNotEmpty($profile['jwksUrl'] ?? '', $provider);
            $this->assertIsBool($profile['nonceRequired'] ?? null, $provider);
        }
    }

    /**
     * Apple must require a nonce: ASAuthorizationController always supports
     * one, and a nonce-less Apple token is replayable for its full lifetime.
     * Google stays lenient because common Credential Manager integrations
     * omit the nonce.
     */
    public function testAppleIdTokenRequiresNonce(): void
    {
        $providers = Config::getParam('oAuthProviders');

        $this->assertTrue($providers['apple']['idToken']['nonceRequired']);
        $this->assertFalse($providers['google']['idToken']['nonceRequired']);
    }
}
