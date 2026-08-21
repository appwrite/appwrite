<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC;

use Appwrite\Auth\OIDC\Profiles;
use PHPUnit\Framework\TestCase;

final class ProfilesTest extends TestCase
{
    /**
     * Apple must require a nonce: ASAuthorizationController always supports
     * one, and a nonce-less Apple token is replayable for its full lifetime.
     * Google stays lenient because common Credential Manager integrations
     * omit the nonce.
     */
    public function testAppleRequiresNonce(): void
    {
        $this->assertTrue(Profiles::get('apple')->nonceRequired);
        $this->assertFalse(Profiles::get('google')->nonceRequired);
    }

    public function testUnknownProviderHasNoProfile(): void
    {
        $this->assertNull(Profiles::get('github'));
    }
}
