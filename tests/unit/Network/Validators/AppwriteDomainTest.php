<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Domain\Validator\AppwriteDomain;
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
        $sitesDomain = \Utopia\System\System::getEnv('_APP_DOMAIN_SITES');
        $functionsDomain = \Utopia\System\System::getEnv('_APP_DOMAIN_FUNCTIONS');

        if (!empty($sitesDomain)) {
            $this->assertEquals(true, $this->validator->isValid('api.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('test.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('myapp.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('staging.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('prod.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('app123.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('test-app.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('my-awesome-app.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('a.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('x1.' . $sitesDomain));

            $this->assertEquals(false, $this->validator->isValid('api.dev.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('foo.bar.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('app.staging.test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('sub.domain.example.' . $sitesDomain));

            $this->assertEquals(true, $this->validator->isValid('API.' . strtoupper($sitesDomain)));
            $this->assertEquals(true, $this->validator->isValid('Test.' . ucfirst($sitesDomain)));
            $this->assertEquals(true, $this->validator->isValid('MyApp.' . $sitesDomain));

            $this->assertEquals(false, $this->validator->isValid('my app.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('test .' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid(' api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('api.' . $sitesDomain . ' '));
            $this->assertEquals(false, $this->validator->isValid('app@test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('app#test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('app$test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('app%test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('app_test.' . $sitesDomain));

            $this->assertEquals(false, $this->validator->isValid('.api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('api.' . $sitesDomain . '.'));
            $this->assertEquals(false, $this->validator->isValid('api..' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid($sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('.' . $sitesDomain . '.'));
            $this->assertEquals(false, $this->validator->isValid('..' . $sitesDomain));

            $this->assertEquals(false, $this->validator->isValid('commit-api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('commit-test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('commit-123.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('branch-api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('branch-test.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('branch-123.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('COMMIT-api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('BRANCH-test.' . $sitesDomain));

            $this->assertEquals(true, $this->validator->isValid('commitment.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('branching.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('my-commit.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('my-branch.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('pre-commit.' . $sitesDomain));
            $this->assertEquals(true, $this->validator->isValid('post-branch.' . $sitesDomain));

            $this->assertEquals(false, $this->validator->isValid('.api.' . $sitesDomain));
            $this->assertEquals(false, $this->validator->isValid('api..' . $sitesDomain));
        }

        if (!empty($functionsDomain)) {
            $this->assertEquals(true, $this->validator->isValid('api.' . $functionsDomain));
            $this->assertEquals(true, $this->validator->isValid('test.' . $functionsDomain));
            $this->assertEquals(true, $this->validator->isValid('myapp.' . $functionsDomain));

            $this->assertEquals(false, $this->validator->isValid('api.dev.' . $functionsDomain));
            $this->assertEquals(false, $this->validator->isValid('foo.bar.' . $functionsDomain));
        }

        $this->assertEquals(true, $this->validator->isValid('example.com'));
        $this->assertEquals(true, $this->validator->isValid('api.example.com'));
        $this->assertEquals(true, $this->validator->isValid('test.google.com'));
        $this->assertEquals(true, $this->validator->isValid('app.github.io'));
        $this->assertEquals(true, $this->validator->isValid('myapp.herokuapp.com'));
        $this->assertEquals(true, $this->validator->isValid('sub.domain.example.com'));

        // Invalid subdomain formats
        $this->assertEquals(false, $this->validator->isValid('-api.' . $sitesDomain));
        $this->assertEquals(false, $this->validator->isValid('api-.' . $sitesDomain));
        $this->assertEquals(false, $this->validator->isValid('-test-.' . $sitesDomain));
        $this->assertEquals(false, $this->validator->isValid('-.' . $sitesDomain));

        // Too long subdomain (over 63 characters)
        $longSubdomain = str_repeat('a', 64) . '.' . $sitesDomain;
        $this->assertEquals(false, $this->validator->isValid($longSubdomain));

        // Exactly 63 characters should be valid
        $maxLengthSubdomain = str_repeat('a', 63) . '.' . $sitesDomain;
        $this->assertEquals(true, $this->validator->isValid($maxLengthSubdomain));

        // Single character subdomain should be valid
        $this->assertEquals(true, $this->validator->isValid('a.' . $sitesDomain));

        // Numbers in subdomain
        $this->assertEquals(true, $this->validator->isValid('123.' . $sitesDomain));
        $this->assertEquals(true, $this->validator->isValid('api123.' . $sitesDomain));
        $this->assertEquals(true, $this->validator->isValid('123api.' . $sitesDomain));

        // Mixed case with hyphens
        $this->assertEquals(true, $this->validator->isValid('My-Test-App.' . $sitesDomain));
        $this->assertEquals(true, $this->validator->isValid('app-v2.' . $sitesDomain));
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
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('one-level subdomain', $description);
    }
}
