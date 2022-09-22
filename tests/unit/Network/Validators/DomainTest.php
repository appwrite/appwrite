<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    protected ?Domain $domain = null;

    public function setUp(): void
    {
        $this->domain = new Domain();
    }

    public function tearDown(): void
    {
        $this->domain = null;
    }

    public function testIsValid(): void
    {
        // Assertions
        $this->assertEquals(true, $this->domain->isValid('example.com'));
        $this->assertEquals(true, $this->domain->isValid('subdomain.example.com'));
        $this->assertEquals(true, $this->domain->isValid('subdomain.example-app.com'));
        $this->assertEquals(true, $this->domain->isValid('subdomain.example_app.com'));
        $this->assertEquals(true, $this->domain->isValid('subdomain-new.example.com'));
        $this->assertEquals(true, $this->domain->isValid('subdomain_new.example.com'));
        $this->assertEquals(true, $this->domain->isValid('localhost'));
        $this->assertEquals(true, $this->domain->isValid('appwrite.io'));
        $this->assertEquals(true, $this->domain->isValid('appwrite.org'));
        $this->assertEquals(true, $this->domain->isValid('appwrite.org'));
        $this->assertEquals(false, $this->domain->isValid(false));
        $this->assertEquals(false, $this->domain->isValid('.'));
        $this->assertEquals(false, $this->domain->isValid('..'));
        $this->assertEquals(false, $this->domain->isValid(''));
        $this->assertEquals(false, $this->domain->isValid(['string', 'string']));
        $this->assertEquals(false, $this->domain->isValid(1));
        $this->assertEquals(false, $this->domain->isValid(1.2));
    }
}
