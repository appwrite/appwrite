<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Signing;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\AgingMemory;
use Utopia\Cache\Cache;
use Utopia\Database\Document;

final class SigningTest extends TestCase
{
    private AgingMemory $memory;

    private Signing $signing;

    private Document $project;

    protected function setUp(): void
    {
        $this->memory = new AgingMemory();
        $this->signing = new Signing(new Cache($this->memory));
        $this->project = new Document(['$id' => 'project1', '$sequence' => '7']);
    }

    public function testTokenIs256RandomBits(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->assertSame(64, \strlen($token));
        $this->assertTrue(\ctype_xdigit($token));
        $this->assertNotSame($token, $this->signing->issue($this->project, 'app1', 'artifact2', 1));
    }

    public function testOnlyTheTokenHashIsStored(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $stored = (string) \json_encode($this->memory->store);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringContainsString(\hash('sha256', $token), $stored);
    }

    public function testTokenRedeemsOnceToItsBinding(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 2);

        $this->assertSame(['appId' => 'app1', 'subjectId' => 'artifact1', 'attempt' => 2], $this->signing->redeem($this->project, $token));
        $this->assertNull($this->signing->redeem($this->project, $token));
    }

    public function testTokenExpires(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->memory->age(Signing::TTL + 1);

        $this->assertNull($this->signing->redeem($this->project, $token));
    }

    public function testTokenIsValidWithinItsLifetime(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->memory->age(Signing::TTL - 5);

        $this->assertNotNull($this->signing->redeem($this->project, $token));
    }

    public function testTokenIsBoundToItsProject(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->assertNull($this->signing->redeem(new Document(['$id' => 'project2', '$sequence' => '8']), $token));
        $this->assertNotNull($this->signing->redeem($this->project, $token));
    }

    public function testRevokedTokenDoesNotRedeem(): void
    {
        $token = $this->signing->issue($this->project, 'app1', 'artifact1', 1);
        $other = $this->signing->issue($this->project, 'app1', 'artifact1', 2);

        $this->signing->revoke($this->project, 'artifact1', 1);

        $this->assertNull($this->signing->redeem($this->project, $token));
        $this->assertNotNull($this->signing->redeem($this->project, $other));
    }

    public function testReissueRevokesThePreviousToken(): void
    {
        $first = $this->signing->issue($this->project, 'app1', 'artifact1', 1);
        $second = $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->assertNull($this->signing->redeem($this->project, $first));
        $this->assertNotNull($this->signing->redeem($this->project, $second));
    }

    public function testMalformedTokenIsRefused(): void
    {
        $this->signing->issue($this->project, 'app1', 'artifact1', 1);

        $this->assertNull($this->signing->redeem($this->project, ''));
        $this->assertNull($this->signing->redeem($this->project, \str_repeat('z', 64)));
        $this->assertNull($this->signing->redeem($this->project, \str_repeat('a', 63)));
    }

    public function testBundleCarriesExactlyTheSignJobKeys(): void
    {
        $identity = new Document(['secret' => [
            'keystore' => 'a2V5c3RvcmU=',
            'storePassword' => 'store',
            'keyAlias' => 'upload',
            'keyPassword' => 'key',
            'certificate' => 'not-for-the-job',
        ]]);

        $this->assertSame([
            'keystore' => 'a2V5c3RvcmU=',
            'storePassword' => 'store',
            'keyAlias' => 'upload',
            'keyPassword' => 'key',
        ], Signing::bundle($identity));
    }

    public function testBundleOfAnIdentityWithoutSecretIsEmpty(): void
    {
        $this->assertSame([
            'keystore' => '',
            'storePassword' => '',
            'keyAlias' => '',
            'keyPassword' => '',
        ], Signing::bundle(new Document()));
    }
}
