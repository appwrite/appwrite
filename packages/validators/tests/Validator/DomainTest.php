<?php

declare(strict_types=1);

/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    protected Domain $domain;

    public function setUp(): void
    {
        $this->domain = new Domain();
    }

    public function testIsValid(): void
    {
        // Assertions
        $this->assertTrue($this->domain->isValid('example.com'));
        $this->assertTrue($this->domain->isValid('subdomain.example.com'));
        $this->assertTrue($this->domain->isValid('subdomain.example-app.com'));
        $this->assertFalse($this->domain->isValid('subdomain.example_app.com'));
        $this->assertTrue($this->domain->isValid('subdomain-new.example.com'));
        $this->assertFalse($this->domain->isValid('subdomain_new.example.com'));
        $this->assertTrue($this->domain->isValid('localhost'));
        $this->assertTrue($this->domain->isValid('example.io'));
        $this->assertTrue($this->domain->isValid('example.org'));
        $this->assertTrue($this->domain->isValid('example.org'));
        $this->assertFalse($this->domain->isValid(false));
        $this->assertFalse($this->domain->isValid('api.appwrite.io.'));
        $this->assertFalse($this->domain->isValid('.api.appwrite.io'));
        $this->assertFalse($this->domain->isValid('.api.appwrite.io'));
        $this->assertFalse($this->domain->isValid('api..appwrite.io'));
        $this->assertFalse($this->domain->isValid('api-.appwrite.io'));
        $this->assertFalse($this->domain->isValid('api.-appwrite.io'));
        $this->assertFalse($this->domain->isValid('app write.io'));
        $this->assertFalse($this->domain->isValid(' appwrite.io'));
        $this->assertFalse($this->domain->isValid('appwrite.io '));
        $this->assertFalse($this->domain->isValid('-appwrite.io'));
        $this->assertFalse($this->domain->isValid('appwrite.io-'));
        $this->assertFalse($this->domain->isValid('.'));
        $this->assertFalse($this->domain->isValid('..'));
        $this->assertFalse($this->domain->isValid(''));
        $this->assertFalse($this->domain->isValid(['string', 'string']));
        $this->assertFalse($this->domain->isValid(1));
        $this->assertFalse($this->domain->isValid(1.2));
    }

    /**
     * Test domain validation with hostnames flag set to false (permissive mode)
     */
    public function testDomainValidationWithHostnamesFalse(): void
    {
        // Create validator with hostnames=false for permissive validation
        $permissiveValidator = new Domain([], false);

        $this->assertTrue($permissiveValidator->isValid('xn--e1afmkfd.xn--p1ai')); // пример.рф in punycode
        $this->assertTrue($permissiveValidator->isValid('xn--fsq.com')); // 中.com in punycode
        $this->assertTrue($permissiveValidator->isValid('123.com'));
        $this->assertTrue($permissiveValidator->isValid('test123.example.com'));
        $this->assertTrue($permissiveValidator->isValid('localhost'));
        $this->assertTrue($permissiveValidator->isValid('intranet'));
        $this->assertTrue($permissiveValidator->isValid('subdomain_new.example.com'));
        $this->assertTrue($permissiveValidator->isValid('subdomain.example_app.com'));
        $longLabel = str_repeat('a', 63);
        $this->assertTrue($permissiveValidator->isValid($longLabel . '.com'));
        $this->assertTrue($permissiveValidator->isValid('a.b.c.d.example.com'));
        $this->assertTrue($permissiveValidator->isValid('sub1.sub2.sub3.example.org'));
        $this->assertTrue($permissiveValidator->isValid('api-.appwrite.io')); // Dash at end of label
        $this->assertTrue($permissiveValidator->isValid('api.-appwrite.io')); // Dash at start of label
        $this->assertTrue($permissiveValidator->isValid('app write.io')); // Space in domain
        $this->assertTrue($permissiveValidator->isValid(' appwrite.io')); // Leading space
        $this->assertTrue($permissiveValidator->isValid('appwrite.io ')); // Trailing space
        $this->assertTrue($permissiveValidator->isValid('-appwrite.io')); // Leading dash
    }

    /**
     * Test domains that are invalid even with hostnames=false
     */
    public function testInvalidDomainsWithHostnamesFalse(): void
    {
        // Create validator with hostnames=false for permissive validation
        $permissiveValidator = new Domain([], false);

        // These should still be invalid even in permissive mode
        $this->assertFalse($permissiveValidator->isValid('example..com')); // Double dot
        $this->assertFalse($permissiveValidator->isValid('.example.com')); // Leading dot
        $this->assertFalse($permissiveValidator->isValid('example.com.')); // Trailing dot (caught by Domain validator)
        $this->assertFalse($permissiveValidator->isValid('appwrite.io-')); // Trailing dash (caught by Domain validator)

        // Test label too long (more than 63 characters)
        $tooLongLabel = str_repeat('a', 64);
        $this->assertFalse($permissiveValidator->isValid($tooLongLabel . '.com'));

        // Test total domain length too long (more than 253 characters)
        $longDomain = str_repeat('a', 50) . '.' . str_repeat('b', 50) . '.'
                      . str_repeat('c', 50) . '.' . str_repeat('d', 50) . '.'
                      . str_repeat('e', 50) . '.com';
        $this->assertFalse($permissiveValidator->isValid($longDomain));

        // Note: These are actually allowed by FILTER_VALIDATE_DOMAIN without FILTER_FLAG_HOSTNAME
        // but might be unexpected:
        $this->assertTrue($permissiveValidator->isValid('exam ple.com')); // Space in domain
        $this->assertTrue($permissiveValidator->isValid('example@.com')); // @ character
        $this->assertTrue($permissiveValidator->isValid('example#.com')); // # character
        $this->assertTrue($permissiveValidator->isValid('http://example.com')); // Protocol
        $this->assertTrue($permissiveValidator->isValid('example.com:8080')); // Port
        $this->assertTrue($permissiveValidator->isValid('example.com/path')); // Path
    }

    /**
     * Test allowEmpty parameter
     */
    public function testAllowEmpty(): void
    {
        // By default, empty string is invalid
        $this->assertFalse($this->domain->isValid(''));

        // With allowEmpty=true, empty string is valid
        $domainAllowEmpty = new Domain([], true, true);
        $this->assertTrue($domainAllowEmpty->isValid(''));

        // null is still invalid even with allowEmpty=true
        $this->assertFalse($domainAllowEmpty->isValid(null));

        // Valid domains still pass with allowEmpty=true
        $this->assertTrue($domainAllowEmpty->isValid('example.com'));
        $this->assertTrue($domainAllowEmpty->isValid('subdomain.example.com'));

        // Invalid domains still fail with allowEmpty=true
        $this->assertFalse($domainAllowEmpty->isValid('invalid..domain'));
        $this->assertFalse($domainAllowEmpty->isValid(1));
    }

    public function testRestrictions(): void
    {
        $validator = new Domain([
            Domain::createRestriction('appwrite.network', 3, ['preview-', 'branch-']),
            Domain::createRestriction('fra.appwrite.run', 4),
        ]);

        $this->assertTrue($validator->isValid('google.com'));
        $this->assertTrue($validator->isValid('stage.google.com'));
        $this->assertTrue($validator->isValid('shard4.stage.google.com'));

        $this->assertFalse($validator->isValid('appwrite.network'));
        $this->assertFalse($validator->isValid('preview-a.appwrite.network'));
        $this->assertFalse($validator->isValid('branch-a.appwrite.network'));
        $this->assertTrue($validator->isValid('google.appwrite.network'));
        $this->assertFalse($validator->isValid('stage.google.appwrite.network'));
        $this->assertFalse($validator->isValid('shard4.stage.google.appwrite.network'));

        $this->assertFalse($validator->isValid('fra.appwrite.run'));
        $this->assertTrue($validator->isValid('appwrite.run'));
        $this->assertTrue($validator->isValid('google.fra.appwrite.run'));
        $this->assertFalse($validator->isValid('shard4.google.fra.appwrite.run'));
        $this->assertTrue($validator->isValid('branch-google.fra.appwrite.run'));
        $this->assertTrue($validator->isValid('preview-google.fra.appwrite.run'));
    }

    /**
     * Test the hostnames parameter functionality
     */
    public function testHostnamesParameter(): void
    {
        // Test with hostnames=true (default, strict mode with FILTER_FLAG_HOSTNAME)
        $strictValidator = new Domain([], true);
        $this->assertFalse($strictValidator->isValid('subdomain_new.example.com'));
        $this->assertFalse($strictValidator->isValid('subdomain.example_app.com'));
        $this->assertFalse($strictValidator->isValid('sub_domain.example.com'));
        $this->assertFalse($strictValidator->isValid('app write.io'));
        $this->assertFalse($strictValidator->isValid('api-.appwrite.io'));
        $this->assertFalse($strictValidator->isValid('api.-appwrite.io'));

        // Test with hostnames=false (permissive mode without FILTER_FLAG_HOSTNAME)
        $permissiveValidator = new Domain([], false);
        $this->assertTrue($permissiveValidator->isValid('subdomain_new.example.com'));
        $this->assertTrue($permissiveValidator->isValid('subdomain.example_app.com'));
        $this->assertTrue($permissiveValidator->isValid('sub_domain.example.com'));
        $this->assertTrue($permissiveValidator->isValid('app write.io'));
        $this->assertTrue($permissiveValidator->isValid('api-.appwrite.io'));
        $this->assertTrue($permissiveValidator->isValid('api.-appwrite.io'));

        // Domains without underscores should be valid in both modes
        $this->assertTrue($strictValidator->isValid('subdomain.example.com'));
        $this->assertTrue($strictValidator->isValid('subdomain-new.example.com'));
        $this->assertTrue($permissiveValidator->isValid('subdomain.example.com'));
        $this->assertTrue($permissiveValidator->isValid('subdomain-new.example.com'));
    }
}
