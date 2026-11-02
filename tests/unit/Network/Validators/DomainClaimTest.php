<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DomainClaim;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\CannedDNS;

final class DomainClaimTest extends TestCase
{
    protected function setUp(): void
    {
        CannedDNS::$answers = [];
    }

    protected function tearDown(): void
    {
        CannedDNS::$answers = [];
    }

    public function testUnclaimedDomain(): void
    {
        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    public function testClaimedByProject(): void
    {
        CannedDNS::$answers = ['_appwrite.app.example.com' => ['project1']];

        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    public function testClaimedByAnotherProject(): void
    {
        CannedDNS::$answers = ['_appwrite.app.example.com' => ['project2']];

        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertFalse($validator->isValid('app.example.com'));
        $this->assertStringContainsString('app.example.com', $validator->getDescription());
        $this->assertStringContainsString('_appwrite.app.example.com', $validator->getDescription());
        $this->assertStringContainsString('project1', $validator->getDescription());
    }

    public function testClaimAmongUnrelatedRecords(): void
    {
        CannedDNS::$answers = ['_appwrite.app.example.com' => ['project2', ' project1 ', 'project3']];

        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    public function testClaimOnAnotherHostname(): void
    {
        // A claim one label up says nothing about this hostname
        CannedDNS::$answers = ['_appwrite.example.com' => ['project2']];

        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    public function testEmptyDomain(): void
    {
        $validator = new DomainClaim('project1', ['8.8.8.8'], CannedDNS::class);

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertSame('string', $validator->getType());
        $this->assertFalse($validator->isArray());
    }
}
