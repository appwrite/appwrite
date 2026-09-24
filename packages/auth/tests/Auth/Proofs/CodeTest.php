<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Proofs;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Proofs\Code;

final class CodeTest extends TestCase
{
    protected Code $code;

    protected function setUp(): void
    {
        $this->code = new Code(); // Using default length of 6
    }

    public function testGenerate(): void
    {
        $proof = $this->code->generate();

        $this->assertNotEmpty($proof);
        $this->assertSame(6, \strlen($proof)); // Default code length
        $this->assertMatchesRegularExpression('/^\d{6}$/', $proof);
    }

    public function testHash(): void
    {
        $proof = $this->code->generate();
        $hash = $this->code->hash($proof);

        $this->assertNotEmpty($hash);
    }

    public function testVerify(): void
    {
        $proof = $this->code->generate();
        $hash = $this->code->hash($proof);

        $this->assertTrue($this->code->verify($proof, $hash));
        $this->assertFalse($this->code->verify('000000', $hash));
    }

    public function testCustomLength(): void
    {
        $code = new Code(8);
        $proof = $code->generate();

        $this->assertSame(8, \strlen($proof));
        $this->assertMatchesRegularExpression('/^\d{8}$/', $proof);
    }

    public function testGetLength(): void
    {
        $this->assertSame(6, $this->code->getLength());

        $code = new Code(8);
        $this->assertSame(8, $code->getLength());
    }

    public function testSetLength(): void
    {
        $this->code->setLength(4);
        $this->assertSame(4, $this->code->getLength());

        $proof = $this->code->generate();
        $this->assertSame(4, \strlen($proof));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $proof);
    }

    public function testSetLengthInvalid(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Code length must be greater than 0');
        $this->code->setLength(0);
    }
}
