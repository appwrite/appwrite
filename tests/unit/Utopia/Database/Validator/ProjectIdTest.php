<?php

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\ProjectId;
use PHPUnit\Framework\TestCase;

class ProjectIdTest extends TestCase
{
    protected ?ProjectId $object = null;

    public function setUp(): void
    {
        $this->object = new ProjectId();
    }

    public function tearDown(): void
    {
    }

    /**
     * @return array
     */
    public function provideTest(): array
    {
        return [
            'unique()' => ['unique()', true],
            'dashes' => ['as12-df34', true],
            '36 chars' => [\str_repeat('a', 36), true],
            'uppercase' => ['ABC', false],
            'underscore' => ['under_score', false],
            'leading dash' => ['-dash', false],
            'too long' => [\str_repeat('a', 37), false],
        ];
    }

    /**
     * @dataProvider provideTest
     */
    public function testValues(string $input, bool $expected): void
    {
        $this->assertEquals($this->object->isValid($input), $expected);
    }

    public function testCustomMaxLength(): void
    {
        // Test with MongoDB max length (255)
        $validator = new ProjectId(255);
        $this->assertTrue($validator->isValid(\str_repeat('a', 255)));
        $this->assertFalse($validator->isValid(\str_repeat('a', 256)));

        // Test with smaller custom length
        $validator = new ProjectId(10);
        $this->assertTrue($validator->isValid(\str_repeat('a', 10)));
        $this->assertFalse($validator->isValid(\str_repeat('a', 11)));

        // Verify description updates
        $this->assertStringContainsString('10 chars', $validator->getDescription());
    }
}
