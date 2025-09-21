<?php

namespace Tests\Unit\Response\Model;

use Appwrite\Utopia\Response\Model\AlgoArgon2;
use PHPUnit\Framework\TestCase;

class AlgoArgon2Test extends TestCase
{
    private AlgoArgon2 $model;

    protected function setUp(): void
    {
        $this->model = new AlgoArgon2();
    }

    public function testGetName(): void
    {
        $this->assertEquals('AlgoArgon2', $this->model->getName());
    }

    public function testGetType(): void
    {
        $this->assertEquals('algoArgon2', $this->model->getType());
    }

    public function testConvertToApiFormatWithSnakeCase(): void
    {
        $internalOptions = [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536,
            'timeCost' => 4,
            'threads' => 3
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithCamelCase(): void
    {
        $internalOptions = [
            'memoryCost' => 2048,
            'timeCost' => 4,
            'threads' => 3
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 2048,
            'timeCost' => 4,
            'threads' => 3
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithMixedCase(): void
    {
        $internalOptions = [
            'memory_cost' => 65536,
            'timeCost' => 4,
            'threads' => 3
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536,
            'timeCost' => 4,
            'threads' => 3
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithMissingKeys(): void
    {
        $internalOptions = [
            'memory_cost' => 65536
            // Missing timeCost and threads
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536,
            'timeCost' => 4, // Default value
            'threads' => 3   // Default value
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithEmptyArray(): void
    {
        $internalOptions = [];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536, // Default value
            'timeCost' => 4,       // Default value
            'threads' => 3         // Default value
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithNullValues(): void
    {
        $internalOptions = [
            'memory_cost' => null,
            'time_cost' => null,
            'threads' => null
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536, // Default value
            'timeCost' => 4,       // Default value
            'threads' => 3         // Default value
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithOldFormat(): void
    {
        $internalOptions = [
            'type' => 'argon2',
            'memoryCost' => 2048,
            'timeCost' => 4,
            'threads' => 3
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 2048,
            'timeCost' => 4,
            'threads' => 3
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithBothFormats(): void
    {
        $internalOptions = [
            'memory_cost' => 65536,
            'memoryCost' => 2048,  // This should be ignored in favor of snake_case
            'time_cost' => 4,
            'timeCost' => 2,       // This should be ignored in favor of snake_case
            'threads' => 3
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 65536, // Should use snake_case value
            'timeCost' => 4,       // Should use snake_case value
            'threads' => 3
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithInvalidValues(): void
    {
        $internalOptions = [
            'memory_cost' => 'invalid',
            'time_cost' => 'invalid',
            'threads' => 'invalid'
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 'invalid',
            'timeCost' => 'invalid',
            'threads' => 'invalid'
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithZeroValues(): void
    {
        $internalOptions = [
            'memory_cost' => 0,
            'time_cost' => 0,
            'threads' => 0
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => 0,
            'timeCost' => 0,
            'threads' => 0
        ];

        $this->assertEquals($expected, $apiOptions);
    }

    public function testConvertToApiFormatWithNegativeValues(): void
    {
        $internalOptions = [
            'memory_cost' => -1,
            'time_cost' => -1,
            'threads' => -1
        ];

        $apiOptions = AlgoArgon2::convertToApiFormat($internalOptions);

        $expected = [
            'type' => 'argon2',
            'memoryCost' => -1,
            'timeCost' => -1,
            'threads' => -1
        ];

        $this->assertEquals($expected, $apiOptions);
    }
}
