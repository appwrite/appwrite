<?php
namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase
{
    public function testHostnameValidation(): void
    {
        $validator = new Redirect(
            ['appwrite.io', 'localhost', 'example.com'], // allowed hostnames
            [] // no schemes allowed
        );

        // Valid hostnames
        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io:80'));
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback'));
        $this->assertEquals(true, $validator->isValid('https://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost:3000'));
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
            [], // no hostnames allowed
            ['appwrite-ios', 'exp', 'exps'] // allowed schemes
        );

        // Valid schemes
        $this->assertEquals(true, $validator->isValid('appwrite-ios://'));
        $this->assertEquals(true, $validator->isValid('appwrite-ios://callback'));
        $this->assertEquals(true, $validator->isValid('appwrite-ios://com.company.appname/auth/callback'));
        $this->assertEquals(true, $validator->isValid('exp://'));
        $this->assertEquals(true, $validator->isValid('exp://192.168.0.1:19000'));
        $this->assertEquals(true, $validator->isValid('exp://exp.host/@username/app-slug'));
        $this->assertEquals(true, $validator->isValid('exps://exp.host/@username/app-slug'));

        // Invalid schemes
        $this->assertEquals(false, $validator->isValid('https://appwrite.io')); // valid URL but scheme not in list
        $this->assertEquals(false, $validator->isValid('http://localhost'));    // valid URL but scheme not in list
        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname')); // scheme not in list
        $this->assertEquals(false, $validator->isValid('exp-invalid://exp.host')); // similar but not matching scheme
    }

    public function testCombinedValidation(): void
    {
        $validator = new Redirect(
            ['appwrite.io', 'localhost'], // allowed hostnames
            ['appwrite-ios', 'exp'] // allowed schemes
        );

        // Valid hostnames
        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://localhost:3000'));

        // Valid schemes
        $this->assertEquals(true, $validator->isValid('appwrite-ios://callback'));
        $this->assertEquals(true, $validator->isValid('exp://192.168.0.1:19000'));

        // Invalid entries (neither hostname nor scheme match)
        $this->assertEquals(false, $validator->isValid('https://example.com'));
        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname'));
    }

    public function testEdgeCases(): void
    {
        $validator = new Redirect(
            ['appwrite.io', 'empty.host'],
            ['exp', 'appwrite-ios']
        );

        // Empty values
        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));

        // Malformed URLs
        $this->assertEquals(false, $validator->isValid('not-a-url'));
        $this->assertEquals(false, $validator->isValid('http://')); // Missing hostname
        $this->assertEquals(false, $validator->isValid('://hostname')); // Missing scheme

        // URLs with empty hostnames but valid schemes
        $this->assertEquals(true, $validator->isValid('exp://')); // This should be valid as 'exp' is an allowed scheme

        // URLs with query parameters and fragments
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback?token=abc123&session=xyz#fragment'));
        $this->assertEquals(true, $validator->isValid('exp://exp.host/@user/app?release-channel=default&token=123'));

        // URL encoded characters
        $this->assertEquals(true, $validator->isValid('https://appwrite.io/callback?redirect_uri=https%3A%2F%2Fexample.com'));
    }

    public function testDescription(): void
    {
        $validator = new Redirect(
            ['host1.com', 'host2.com'],
            ['scheme1', 'scheme2']
        );

        $expected = 'URL host must be one of: host1.com, host2.com or URL scheme must be one of: scheme1, scheme2';
        $this->assertEquals($expected, $validator->getDescription());
    }

    public function testTypeAndArrayMethods(): void
    {
        $validator = new Redirect([], []);

        $this->assertEquals(false, $validator->isArray());
        $this->assertEquals('string', $validator->getType());
    }
}
