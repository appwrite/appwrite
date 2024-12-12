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
}
