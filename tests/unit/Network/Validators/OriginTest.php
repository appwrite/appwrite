<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function testHostnameValidation(): void
    {
        $validator = new Origin(
            ['appwrite.io', 'localhost', 'example.com'], // allowed hostnames
        );

        // Valid hostnames
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
        $validator = new Origin(
            schemes: ['appwrite-ios', 'exp', 'exps'] // allowed schemes
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
        $validator = new Origin(
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
        $validator = new Origin(
            ['appwrite.io', 'empty.host'],
            ['exp', 'appwrite-ios']
        );

        // Empty values
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
}
