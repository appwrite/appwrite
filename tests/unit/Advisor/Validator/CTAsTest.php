<?php

namespace Tests\Unit\Advisor\Validator;

use Appwrite\Advisor\Validator\CTAs;
use PHPUnit\Framework\TestCase;

class CTAsTest extends TestCase
{
    public function testRejectsNonArray(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid('not-an-array'));
        $this->assertFalse($validator->isValid(42));
        $this->assertFalse($validator->isValid(null));
    }

    public function testAcceptsEmptyArray(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([]));
    }

    public function testAcceptsCompleteEntry(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => [
                'databaseId' => 'main',
                'tableId' => 'orders',
            ],
        ]]));
    }

    public function testAcceptsEntryWithoutParams(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryMissingRequiredKeys(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([['label' => 'x']]));
        $this->assertFalse($validator->isValid([['label' => 'x', 'service' => 'tablesDB']]));
        $this->assertFalse($validator->isValid([['label' => 'x', 'method' => 'createIndex']]));
    }

    public function testRejectsEntryWithEmptyStrings(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => '',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithNonStringFields(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 123,
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithScalarParams(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => 'not-a-map',
        ]]));
    }

    public function testReportsArrayType(): void
    {
        $validator = new CTAs();

        $this->assertTrue($validator->isArray());
        $this->assertSame($validator::TYPE_ARRAY, $validator->getType());
    }

    public function testRejectsMoreThanMaxCount(): void
    {
        $validator = new CTAs(maxCount: 3);

        $entries = [];
        for ($i = 0; $i < 4; $i++) {
            $entries[] = [
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertFalse($validator->isValid($entries));
        $this->assertStringContainsString('maximum of 3', $validator->getDescription());
    }

    public function testAcceptsExactlyMaxCount(): void
    {
        $validator = new CTAs(maxCount: 3);

        $entries = [];
        for ($i = 0; $i < 3; $i++) {
            $entries[] = [
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertTrue($validator->isValid($entries));
    }

    public function testAcceptsObjectParams(): void
    {
        $validator = new CTAs();

        $entry = [
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'createIndex',
            'params' => new \stdClass(),
        ];

        $this->assertTrue($validator->isValid([$entry]));
    }

    public function testRejectsEntryWithEmptyService(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 'Create missing index',
            'service' => '',
            'method' => 'createIndex',
        ]]));
    }

    public function testRejectsEntryWithEmptyMethod(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => '',
        ]]));
    }

    public function testRejectsUnknownService(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'nonExistentService',
            'method' => 'createIndex',
        ]]));
        $this->assertStringContainsString('service', $validator->getDescription());
    }

    public function testRejectsUnknownMethod(): void
    {
        $validator = new CTAs();

        $this->assertFalse($validator->isValid([[
            'label' => 'Create missing index',
            'service' => 'tablesDB',
            'method' => 'nonExistentMethod',
        ]]));
        $this->assertStringContainsString('method', $validator->getDescription());
    }

    public function testAcceptsCustomAllowedLists(): void
    {
        $validator = new CTAs(
            allowedServices: ['custom'],
            allowedMethods: ['doThing'],
        );

        $this->assertTrue($validator->isValid([[
            'label' => 'Custom action',
            'service' => 'custom',
            'method' => 'doThing',
        ]]));

        $this->assertFalse($validator->isValid([[
            'label' => 'Custom action',
            'service' => 'tablesDB',
            'method' => 'doThing',
        ]]));
    }

    public function testDefaultMaxCountIsSixteen(): void
    {
        $validator = new CTAs();

        $this->assertSame(CTAs::MAX_COUNT_DEFAULT, 16);

        $entries = [];
        for ($i = 0; $i < 16; $i++) {
            $entries[] = [
                'label' => 'Label ' . $i,
                'service' => 'tablesDB',
                'method' => 'createIndex',
            ];
        }

        $this->assertTrue($validator->isValid($entries));

        $entries[] = [
            'label' => 'Label 16',
            'service' => 'tablesDB',
            'method' => 'createIndex',
        ];

        $this->assertFalse($validator->isValid($entries));
    }
}
