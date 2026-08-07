<?php

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Microsoft;
use PHPUnit\Framework\TestCase;

class MicrosoftTest extends TestCase
{
    public function testTenantDefaultsToCommonWhenMissing(): void
    {
        $oauth2 = new Microsoft(
            'app-id',
            \json_encode(['clientSecret' => 'secret']),
            'https://example.com/callback'
        );

        $this->assertStringContainsString('/common/oauth2/v2.0/authorize', $oauth2->getLoginURL());
    }

    public function testTenantDefaultsToCommonWhenEmpty(): void
    {
        $oauth2 = new Microsoft(
            'app-id',
            \json_encode(['clientSecret' => 'secret', 'tenantID' => '']),
            'https://example.com/callback'
        );

        $this->assertStringContainsString('/common/oauth2/v2.0/authorize', $oauth2->getLoginURL());
    }

    public function testTenantPreservesExplicitValue(): void
    {
        $oauth2 = new Microsoft(
            'app-id',
            \json_encode(['clientSecret' => 'secret', 'tenantID' => 'organizations']),
            'https://example.com/callback'
        );

        $this->assertStringContainsString('/organizations/oauth2/v2.0/authorize', $oauth2->getLoginURL());
    }
}
