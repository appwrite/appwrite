<?php

declare(strict_types=1);

namespace Utopia\Domains\Validator;

use PHPUnit\Framework\TestCase;

final class PublicDomainTest extends TestCase
{
    protected ?PublicDomain $domain;

    public function setUp(): void
    {
        $this->domain = new PublicDomain();
    }

    public function tearDown(): void
    {
        $this->domain = null;
    }

    public function testIsValid(): void
    {
        $this->assertSame('Value must be a public domain', $this->domain->getDescription());
        // Known public domains
        $this->assertTrue($this->domain->isValid('example.com'));
        $this->assertTrue($this->domain->isValid('google.com'));
        $this->assertTrue($this->domain->isValid('bbc.co.uk'));
        $this->assertTrue($this->domain->isValid('appwrite.io'));
        $this->assertTrue($this->domain->isValid('usa.gov'));
        $this->assertTrue($this->domain->isValid('stanford.edu'));

        // URLs
        $this->assertTrue($this->domain->isValid('http://google.com'));
        $this->assertTrue($this->domain->isValid('http://www.google.com'));
        $this->assertTrue($this->domain->isValid('https://example.com'));

        // Private domains
        $this->assertFalse($this->domain->isValid('localhost'));
        $this->assertFalse($this->domain->isValid('http://localhost'));
        $this->assertFalse($this->domain->isValid('sub.demo.localhost'));
        $this->assertFalse($this->domain->isValid('test.app.internal'));
        $this->assertFalse($this->domain->isValid('home.local'));
        $this->assertFalse($this->domain->isValid('qa.testing.internal'));
        $this->assertFalse($this->domain->isValid('wiki.team.local'));
        $this->assertFalse($this->domain->isValid('example.test'));
    }

    public function testAllowDomains(): void
    {
        // Adding localhost to allowed domains
        PublicDomain::allow(['localhost']);

        // Now localhost should be valid
        $this->assertTrue($this->domain->isValid('localhost'));
        $this->assertTrue($this->domain->isValid('http://localhost'));
        $this->assertFalse($this->domain->isValid('test.app.internal'));

        // Adding more domains to allowed domains
        PublicDomain::allow(['test.app.internal', 'home.local']);

        // Now these domains should be valid
        $this->assertTrue($this->domain->isValid('test.app.internal'));
        $this->assertTrue($this->domain->isValid('home.local'));
    }
}
