<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Attribute;
use Appwrite\Utopia\Database\Validator\Attributes;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Query\Schema\ColumnType;

final class AttributesTest extends TestCase
{
    protected Attributes $object;

    public function setUp(): void
    {
        $this->object = new Attributes();
    }

    public function testStringTypes(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'title', 'type' => ColumnType::String->value, 'size' => 128],
            ['key' => 'slug', 'type' => ColumnType::Varchar->value, 'size' => 128],
            ['key' => 'body', 'type' => ColumnType::Text->value],
            ['key' => 'summary', 'type' => ColumnType::MediumText->value],
            ['key' => 'archive', 'type' => ColumnType::LongText->value],
        ]), $this->object->getDescription());
    }

    public function testStringTypeDefaults(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'body', 'type' => ColumnType::Text->value, 'default' => 'hello'],
        ]), $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'body', 'type' => ColumnType::Text->value, 'default' => 1],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'slug', 'type' => ColumnType::Varchar->value, 'size' => 4, 'default' => 'toolong'],
        ]));
    }

    public function testVarcharRequiresSize(): void
    {
        $this->assertFalse($this->object->isValid([
            ['key' => 'slug', 'type' => ColumnType::Varchar->value],
        ]));
    }

    public function testFormatTypes(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL, 'required' => true],
            ['key' => 'website', 'type' => APP_DATABASE_ATTRIBUTE_URL],
            ['key' => 'address', 'type' => APP_DATABASE_ATTRIBUTE_IP],
            ['key' => 'status', 'type' => APP_DATABASE_ATTRIBUTE_ENUM, 'elements' => ['on', 'off'], 'default' => 'on'],
        ]), $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL, 'default' => 'not-an-email'],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'status', 'type' => APP_DATABASE_ATTRIBUTE_ENUM],
        ]));
    }

    public function testEmailWithoutSize(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'email', 'type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL],
        ]), $this->object->getDescription());
    }

    public function testPublicNumericTypeIsDouble(): void
    {
        $this->assertContains(ColumnType::Double->value, Attribute::types());
        $this->assertNotContains(ColumnType::Float->value, Attribute::types());
        $this->assertTrue($this->object->isValid([
            ['key' => 'score', 'type' => ColumnType::Double->value, 'default' => 1.5],
        ]), $this->object->getDescription());
    }

    public function testUnsupportedType(): void
    {
        $this->assertFalse($this->object->isValid([
            ['key' => 'rel', 'type' => ColumnType::Relationship->value],
        ]));
        $this->assertSame("Invalid type for attribute 'rel': relationship", $this->object->getDescription());
    }

    public function testResolveMatchesDedicatedEndpoints(): void
    {
        $this->assertSame(
            ['type' => ColumnType::Text->value, 'format' => '', 'size' => 65535],
            Attribute::resolve(['key' => 'body', 'type' => ColumnType::Text->value])
        );

        $this->assertSame(
            ['type' => ColumnType::MediumText->value, 'format' => '', 'size' => 16777215],
            Attribute::resolve(['key' => 'body', 'type' => ColumnType::MediumText->value])
        );

        $this->assertSame(
            ['type' => ColumnType::LongText->value, 'format' => '', 'size' => 2147483647],
            Attribute::resolve(['key' => 'body', 'type' => ColumnType::LongText->value])
        );

        $this->assertSame(
            ['type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 254],
            Attribute::resolve(['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL])
        );

        $this->assertSame(
            ['type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_IP, 'size' => 39],
            Attribute::resolve(['key' => 'ip', 'type' => APP_DATABASE_ATTRIBUTE_IP])
        );

        $this->assertSame(
            ['type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_URL, 'size' => 2000],
            Attribute::resolve(['key' => 'url', 'type' => APP_DATABASE_ATTRIBUTE_URL])
        );

        $this->assertSame(
            ['type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_ENUM, 'size' => Database::LENGTH_KEY],
            Attribute::resolve(['key' => 'enum', 'type' => APP_DATABASE_ATTRIBUTE_ENUM])
        );
    }

    public function testResolveKeepsExplicitSize(): void
    {
        $this->assertSame(
            ['type' => ColumnType::String->value, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 512],
            Attribute::resolve(['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 512])
        );

        $this->assertSame(
            ['type' => ColumnType::Varchar->value, 'format' => '', 'size' => 128],
            Attribute::resolve(['key' => 'slug', 'type' => ColumnType::Varchar->value, 'size' => 128])
        );
    }
}
