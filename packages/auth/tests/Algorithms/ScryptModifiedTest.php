<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests\Algorithms;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\ScryptModified;

#[RequiresPhpExtension('scrypt')]
final class ScryptModifiedTest extends TestCase
{
    private ScryptModified $scryptModified;

    protected function setUp(): void
    {
        $this->scryptModified = new ScryptModified();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->scryptModified->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertTrue($this->scryptModified->verify($password, $hash));
        $this->assertFalse($this->scryptModified->verify('wrongpassword', $hash));
    }

    public function testCustomOptions(): void
    {
        $this->scryptModified->setSalt(base64_encode('custom-salt'))
            ->setSaltSeparator(base64_encode('custom-separator'))
            ->setSignerKey(base64_encode('custom-signer-key'));

        $password = 'test123';
        $hash = $this->scryptModified->hash($password);

        $this->assertTrue($this->scryptModified->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('scryptMod', $this->scryptModified->getName());
    }
}
