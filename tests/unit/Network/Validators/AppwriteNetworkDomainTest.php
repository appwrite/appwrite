<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\AppwriteNetworkDomain;
use PHPUnit\Framework\TestCase;

class AppwriteNetworkDomainTest extends TestCase
{
    protected ?AppwriteNetworkDomain $validator = null;

    public function setUp(): void
    {
        putenv('_APP_DOMAIN_SITES=.appwrite.network');
        $this->validator = new AppwriteNetworkDomain();
    }

    public function tearDown(): void
    {
        $this->validator = null;
    }

    public function testValidSingleSubdomains(): void
    {
        // Valid single-level subdomains for appwrite.network
        $this->assertEquals(true, $this->validator->isValid('api.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('app.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('test.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('myapp.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('123.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('my-app.appwrite.network'));
        $this->assertEquals(true, $this->validator->isValid('a.appwrite.network'));

        // Test case insensitivity
        $this->assertEquals(true, $this->validator->isValid('API.APPWRITE.NETWORK'));
        $this->assertEquals(true, $this->validator->isValid('Api.Appwrite.Network'));
    }

    public function testInvalidSubSubdomains(): void
    {
        // Invalid sub-subdomains for appwrite.network
        $this->assertEquals(false, $this->validator->isValid('api.staging.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('app.dev.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('test.beta.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('foo.bar.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('a.b.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('very.long.subdomain.appwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('multi.level.deep.appwrite.network'));

        // Test case insensitivity
        $this->assertEquals(false, $this->validator->isValid('API.STAGING.APPWRITE.NETWORK'));
        $this->assertEquals(false, $this->validator->isValid('Api.Dev.Appwrite.Network'));
    }

    public function testNonAppwriteNetworkDomains(): void
    {
        // Non-appwrite.network domains should pass validation (not our concern)
        $this->assertEquals(true, $this->validator->isValid('example.com'));
        $this->assertEquals(true, $this->validator->isValid('api.example.com'));
        $this->assertEquals(true, $this->validator->isValid('deep.nested.example.com'));
        $this->assertEquals(true, $this->validator->isValid('google.com'));
        $this->assertEquals(true, $this->validator->isValid('sub.domain.test.io'));
        $this->assertEquals(true, $this->validator->isValid('localhost'));
        $this->assertEquals(true, $this->validator->isValid('127.0.0.1'));

        // Similar but different domains
        $this->assertEquals(true, $this->validator->isValid('appwrite.com'));
        $this->assertEquals(true, $this->validator->isValid('api.appwrite.com'));
        $this->assertEquals(true, $this->validator->isValid('test.appwrite.org'));
        $this->assertEquals(true, $this->validator->isValid('notappwrite.network'));
    }

    public function testDoubleSubdomainCustomDomain(): void
    {
        // Double subdomain for a custom domain should be allowed
        $this->assertEquals(true, $this->validator->isValid('stage.dashboard.example.com'));
        $this->assertEquals(true, $this->validator->isValid('foo.bar.baz.example.com'));
    }

            public function testEdgeCases(): void
        {
            // Empty and invalid values should pass (let other validators handle them)
            $this->assertEquals(true, $this->validator->isValid(''));
            $this->assertEquals(true, $this->validator->isValid(null));
            $this->assertEquals(true, $this->validator->isValid(false));
            $this->assertEquals(true, $this->validator->isValid(123));
            $this->assertEquals(true, $this->validator->isValid([]));

            // Just the root domain (unlikely but should be valid)
            $this->assertEquals(true, $this->validator->isValid('appwrite.network'));

            // Domain with trailing/leading dots
            $this->assertEquals(false, $this->validator->isValid('api.test.appwrite.network.'));
            $this->assertEquals(false, $this->validator->isValid('.api.test.appwrite.network'));

            // Domains with spaces should be invalid
            $this->assertEquals(false, $this->validator->isValid('my app.appwrite.network'));
            $this->assertEquals(false, $this->validator->isValid('api .appwrite.network'));
            $this->assertEquals(false, $this->validator->isValid(' api.appwrite.network'));
            $this->assertEquals(false, $this->validator->isValid('api.appwrite.network '));
            $this->assertEquals(false, $this->validator->isValid('api.app write.network'));
            $this->assertEquals(false, $this->validator->isValid("api\tapp.appwrite.network"));
        }

    public function testValidatorProperties(): void
    {
        $suffix = getenv('_APP_DOMAIN_SITES') ?: '.appwrite.network';
        $expectedDescription = "Sub-subdomains are not allowed for {$suffix}. Only one level of subdomain is permitted.";
        $this->assertEquals($expectedDescription, $this->validator->getDescription());
        $this->assertEquals(false, $this->validator->isArray());
        $this->assertEquals('string', $this->validator->getType());
    }
}
