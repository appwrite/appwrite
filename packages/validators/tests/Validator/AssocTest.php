<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class AssocTest extends TestCase
{
    protected ?Assoc $assoc;

    public function setUp(): void
    {
        $this->assoc = new Assoc();
    }

    public function tearDown(): void
    {
        $this->assoc = null;
    }

    public function testCanValidateAssocArray(): void
    {
        $this->assertTrue($this->assoc->isValid(['1' => 'a', '0' => 'b', '2' => 'c']));
        $this->assertTrue($this->assoc->isValid(['a' => 'a', 'b' => 'b', 'c' => 'c']));
        $this->assertTrue($this->assoc->isValid([]));
        $this->assertTrue($this->assoc->isValid(['value' => str_repeat('-', 62000)]));
        $this->assertFalse($this->assoc->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_OBJECT, $this->assoc->getType());
    }

    public function testCantValidateSequentialArray(): void
    {
        $this->assertFalse($this->assoc->isValid([0 => 'string', 1 => 'string']));
        $this->assertFalse($this->assoc->isValid(['a']));
        $this->assertFalse($this->assoc->isValid(['a', 'b', 'c']));
        $this->assertFalse($this->assoc->isValid(['0' => 'a', '1' => 'b', '2' => 'c']));
    }

    public function testCantValidateAssocArrayWithOver65kCharacters(): void
    {
        $this->assertFalse($this->assoc->isValid(['value' => str_repeat('-', 66000)]));
    }
}
