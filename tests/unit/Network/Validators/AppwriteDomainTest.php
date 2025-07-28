<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\AppwriteDomain;
use PHPUnit\Framework\TestCase;

class AppwriteDomainTest extends TestCase
{
    protected ?AppwriteDomain $validator = null;

    public function setUp(): void
    {
        $this->validator = new AppwriteDomain();
    }

    public function tearDown(): void
    {
        $this->validator = null;
    }

    public function testIsValid(): void
    {
        // Get the actual configured suffix from environment (in Docker it's sites.localhost)
        // But test both the environment value and the default value
        $envSuffix = \Utopia\System\System::getEnv('_APP_DOMAIN_SITES', APP_DOMAIN_SITES_SUFFIX);

        // Valid one-level subdomains with environment suffix
        $this->assertEquals(true, $this->validator->isValid('api.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('test.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('myapp.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('staging.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('prod.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('app123.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('test-app.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('my-awesome-app.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('a.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('x1.' . $envSuffix));

        // Case insensitive validation
        $this->assertEquals(true, $this->validator->isValid('API.' . strtoupper($envSuffix)));
        $this->assertEquals(true, $this->validator->isValid('Test.' . ucfirst($envSuffix)));
        $this->assertEquals(true, $this->validator->isValid('MyApp.' . $envSuffix));

        // Invalid sub-subdomains (multiple levels)
        $this->assertEquals(false, $this->validator->isValid('api.dev.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('foo.bar.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('app.staging.test.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('sub.domain.example.' . $envSuffix));

        // Non-appwrite.network domains
        $this->assertEquals(false, $this->validator->isValid('example.com'));
        $this->assertEquals(false, $this->validator->isValid('api.example.com'));
        $this->assertEquals(false, $this->validator->isValid('test.google.com'));
        $this->assertEquals(false, $this->validator->isValid('app.github.io'));
        $this->assertEquals(false, $this->validator->isValid('myapp.herokuapp.com'));

        // Domains with spaces and invalid characters
        $this->assertEquals(false, $this->validator->isValid('my app.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('test .' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid(' api.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('api.' . $envSuffix . ' '));
        $this->assertEquals(false, $this->validator->isValid('app@test.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('app#test.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('app$test.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('app%test.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('app_test.' . $envSuffix));

        // Edge cases with dots
        $this->assertEquals(false, $this->validator->isValid('.api.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('api..' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('api.' . $envSuffix . '.'));
        $this->assertEquals(false, $this->validator->isValid('.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('..' . $envSuffix));

        // Just the suffix without subdomain
        $this->assertEquals(false, $this->validator->isValid($envSuffix));
        $this->assertEquals(false, $this->validator->isValid('.' . $envSuffix));

        // Empty and null values
        $this->assertEquals(false, $this->validator->isValid(''));
        $this->assertEquals(false, $this->validator->isValid(' '));
        $this->assertEquals(false, $this->validator->isValid(null));
        $this->assertEquals(false, $this->validator->isValid(false));

        // Non-string types
        $this->assertEquals(false, $this->validator->isValid(123));
        $this->assertEquals(false, $this->validator->isValid(12.34));
        $this->assertEquals(false, $this->validator->isValid(['api.appwrite.network']));
        $this->assertEquals(false, $this->validator->isValid((object)['domain' => 'api.appwrite.network']));
        $this->assertEquals(false, $this->validator->isValid(true));

        // Invalid subdomain formats
        $this->assertEquals(false, $this->validator->isValid('-api.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('api-.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('-test-.' . $envSuffix));
        $this->assertEquals(false, $this->validator->isValid('-.' . $envSuffix));

        // Too long subdomain (over 63 characters)
        $longSubdomain = str_repeat('a', 64) . '.' . $envSuffix;
        $this->assertEquals(false, $this->validator->isValid($longSubdomain));

        // Exactly 63 characters should be valid
        $maxLengthSubdomain = str_repeat('a', 63) . '.' . $envSuffix;
        $this->assertEquals(true, $this->validator->isValid($maxLengthSubdomain));

        // Single character subdomain should be valid
        $this->assertEquals(true, $this->validator->isValid('a.' . $envSuffix));

        // Wrong suffix variations (use hardcoded examples for non-matching domains)
        $this->assertEquals(false, $this->validator->isValid('api.appwrite.com'));
        $this->assertEquals(false, $this->validator->isValid('api.appwrite.org'));
        $this->assertEquals(false, $this->validator->isValid('api.notappwrite.network'));
        $this->assertEquals(false, $this->validator->isValid('api.' . $envSuffix . '.com'));

        // Numbers in subdomain
        $this->assertEquals(true, $this->validator->isValid('123.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('api123.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('123api.' . $envSuffix));

        // Mixed case with hyphens
        $this->assertEquals(true, $this->validator->isValid('My-Test-App.' . $envSuffix));
        $this->assertEquals(true, $this->validator->isValid('app-v2.' . $envSuffix));
    }

    public function testGetType(): void
    {
        $this->assertEquals('string', $this->validator->getType());
    }

    public function testIsArray(): void
    {
        $this->assertEquals(false, $this->validator->isArray());
    }

    public function testGetDescription(): void
    {
        $description = $this->validator->getDescription();
        $this->assertIsString($description);

                       // Get the environment suffix to check it's in the description
               $envSuffix = \Utopia\System\System::getEnv('_APP_DOMAIN_SITES', APP_DOMAIN_SITES_SUFFIX);
        $this->assertStringContainsString($envSuffix, $description);
        $this->assertStringContainsString('one-level subdomain', $description);
    }
}
