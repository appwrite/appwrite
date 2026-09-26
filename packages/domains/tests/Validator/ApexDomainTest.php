<?php

declare(strict_types=1);

namespace Utopia\Domains\Validator;

use PHPUnit\Framework\TestCase;

final class ApexDomainTest extends TestCase
{
    protected ?ApexDomain $domain;

    public function setUp(): void
    {
        $this->domain = new ApexDomain();
    }

    public function tearDown(): void
    {
        $this->domain = null;
    }

    public function testIsValid(): void
    {
        // Description
        $this->assertSame('Value must be a public apex domain', $this->domain->getDescription());

        // Valid apex domains
        $this->assertTrue($this->domain->isValid('example.com'));
        $this->assertTrue($this->domain->isValid('google.com'));
        $this->assertTrue($this->domain->isValid('bbc.co.uk'));
        $this->assertTrue($this->domain->isValid('appwrite.io'));
        $this->assertTrue($this->domain->isValid('usa.gov'));
        $this->assertTrue($this->domain->isValid('stanford.edu'));
        $this->assertTrue($this->domain->isValid('http://google.com'));

        // Invalid apex domains
        $this->assertFalse($this->domain->isValid('blog.bbc.co.uk'));
        $this->assertFalse($this->domain->isValid('www.google.com'));
        $this->assertFalse($this->domain->isValid('test.usa.gov'));
        $this->assertFalse($this->domain->isValid('test.com.test'));
        $this->assertFalse($this->domain->isValid('http://www.google.com'));
    }
}
