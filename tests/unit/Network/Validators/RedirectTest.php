<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase
{
    private function buildPlatforms($hostnames = [], $schemes = []): array
    {
        $platforms = [];

        foreach ($hostnames as $hostname) {
            $platforms[] = [
                'type' => Platform::TYPE_WEB,
                'hostname' => $hostname,
                'name' => $hostname,
            ];
        }

        foreach ($schemes as $scheme) {
            $platforms[] = [
                'type' => Platform::TYPE_SCHEME,
                'key' => $scheme,
                'name' => $scheme,
            ];
        }

        return $platforms;
    }

    public function testHostnameValidation(): void
    {
        $validator = new Redirect(
            $this->buildPlatforms(['appwrite.io', 'localhost', 'example.com']), // allowed hostnames
        );

        // Valid hostnames with http/https
        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io:80'));
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback'));
        $this->assertEquals(true, $validator->isValid('https://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost:3000'));
        $this->assertEquals(true, $validator->isValid('http://localhost/v1/mock/tests/general/oauth2/success'));
        $this->assertEquals(true, $validator->isValid('https://example.com/auth/callback?token=123'));

        // Invalid hostnames
        $this->assertEquals(false, $validator->isValid('https://unauthorized.com'));
        $this->assertEquals(false, $validator->isValid('http://subdomain.appwrite.io')); // subdomain not in allowed list
        $this->assertEquals(false, $validator->isValid('https://app-write.io')); // hyphenated variant not in allowed list
        $this->assertEquals(false, $validator->isValid('ftp://appwrite.io')); // valid hostname but not http/https
    }

    public function testSchemeValidation(): void
    {
        $validator = new Redirect(
            $this->buildPlatforms([], ['appwrite-ios', 'exp', 'exps', 'appwrite-callback-test-project']) // allowed schemes
        );

        // Valid schemes
        $this->assertEquals(true, $validator->isValid('appwrite-ios://'));
        $this->assertEquals(true, $validator->isValid('appwrite-ios://callback'));
        $this->assertEquals(true, $validator->isValid('appwrite-ios://com.company.appname/auth/callback'));
        $this->assertEquals(true, $validator->isValid('exp://'));
        $this->assertEquals(true, $validator->isValid('exp://192.168.0.1:19000'));
        $this->assertEquals(true, $validator->isValid('exp://exp.host/@username/app-slug'));
        $this->assertEquals(true, $validator->isValid('exps://exp.host/@username/app-slug'));
        $this->assertEquals(true, $validator->isValid('appwrite-callback-test-project://auth/callback'));

        // Invalid schemes
        $this->assertEquals(false, $validator->isValid('https://appwrite.io')); // valid URL but scheme not in list and no hostnames
        $this->assertEquals(false, $validator->isValid('http://localhost'));    // valid URL but scheme not in list and no hostnames
        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname')); // scheme not in list
        $this->assertEquals(false, $validator->isValid('exp-invalid://exp.host')); // similar but not matching scheme
    }

    public function testCombinedValidation(): void
    {
        $validator = new Redirect(
            $this->buildPlatforms(
                ['appwrite.io', 'localhost'], // allowed hostnames
                ['appwrite-ios', 'exp', 'appwrite-callback-test-project'] // allowed schemes
            )
        );

        // Valid hostnames
        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://localhost:3000'));

        // Valid schemes
        $this->assertEquals(true, $validator->isValid('appwrite-ios://callback'));
        $this->assertEquals(true, $validator->isValid('exp://192.168.0.1:19000'));
        $this->assertEquals(true, $validator->isValid('appwrite-callback-test-project://auth/callback'));

        // Invalid entries (neither hostname nor scheme match)
        $this->assertEquals(false, $validator->isValid('https://example.com'));
        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname'));
    }

    public function testEdgeCases(): void
    {
        $validator = new Redirect(
            $this->buildPlatforms(
                ['appwrite.io', 'empty.host'],
                ['exp', 'appwrite-ios', 'appwrite-callback-test-project']
            )
        );

        // Empty values should be false for Redirect (unlike Origin which allows empty)
        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));

        // Malformed URLs
        $this->assertEquals(false, $validator->isValid('not-a-url'));
        $this->assertEquals(false, $validator->isValid('http://')); // HTTP missing hostname
        $this->assertEquals(false, $validator->isValid('://hostname')); // Missing scheme

        // URLs with empty hostnames but valid schemes
        $this->assertEquals(true, $validator->isValid('exp://')); // This should be valid as 'exp' is an allowed scheme
        $this->assertEquals(true, $validator->isValid('exp:///'));

        // URLs with query parameters and fragments
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback?token=abc123&session=xyz#fragment'));
        $this->assertEquals(true, $validator->isValid('exp://exp.host/@user/app?release-channel=default&token=123'));

        // URL encoded characters
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback?redirect_uri=https%3A%2F%2Fexample.com'));
    }

    public function testReactNativeSpecificCases(): void
    {
        // Test specifically for React Native OAuth2 scenarios
        $validator = new Redirect(
            $this->buildPlatforms(
                ['localhost'], // For dev
                ['exp', 'appwrite-callback-123456789'] // For Expo and production React Native
            )
        );

        // Expo development scenarios
        $this->assertEquals(true, $validator->isValid('exp://192.168.1.100:19000'));
        $this->assertEquals(true, $validator->isValid('exp://localhost:19000'));
        $this->assertEquals(true, $validator->isValid('exp://exp.host/@username/project-name'));

        // React Native production scenarios with custom callback scheme
        $this->assertEquals(true, $validator->isValid('appwrite-callback-123456789://'));
        $this->assertEquals(true, $validator->isValid('appwrite-callback-123456789://oauth/callback'));
        $this->assertEquals(true, $validator->isValid('appwrite-callback-123456789://auth/result?success=true'));

        // Dev web scenarios
        $this->assertEquals(true, $validator->isValid('http://localhost:3000/auth/callback'));

        // Invalid React Native scenarios
        $this->assertEquals(false, $validator->isValid('appwrite-callback-wrongproject://auth'));
        $this->assertEquals(false, $validator->isValid('invalid-scheme://auth'));
    }

    public function testDescriptionMessages(): void
    {
        $validator = new Redirect([]);

        // Test with invalid scheme
        $this->assertEquals(false, $validator->isValid('invalid-scheme://test'));
        $description = $validator->getDescription();
        $this->assertStringContainsString('Invalid URI', $description);

        // Test with empty value
        $this->assertEquals(false, $validator->isValid(''));
        $description = $validator->getDescription();
        $this->assertStringContainsString('Invalid URI', $description);
    }
}