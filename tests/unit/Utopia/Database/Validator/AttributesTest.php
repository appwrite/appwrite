<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Attribute;
use Appwrite\Utopia\Database\Validator\Attributes;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;

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
            ['key' => 'title', 'type' => Database::VAR_STRING, 'size' => 128],
            ['key' => 'slug', 'type' => Database::VAR_VARCHAR, 'size' => 128],
            ['key' => 'body', 'type' => Database::VAR_TEXT],
            ['key' => 'summary', 'type' => Database::VAR_MEDIUMTEXT],
            ['key' => 'archive', 'type' => Database::VAR_LONGTEXT],
        ]), $this->object->getDescription());
    }

    public function testStringTypeDefaults(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'body', 'type' => Database::VAR_TEXT, 'default' => 'hello'],
        ]), $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'body', 'type' => Database::VAR_TEXT, 'default' => 1],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'slug', 'type' => Database::VAR_VARCHAR, 'size' => 4, 'default' => 'toolong'],
        ]));
    }

    public function testVarcharRequiresSize(): void
    {
        $this->assertFalse($this->object->isValid([
            ['key' => 'slug', 'type' => Database::VAR_VARCHAR],
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
            ['key' => 'email', 'type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL],
        ]), $this->object->getDescription());
    }

    public function testUnsupportedType(): void
    {
        $this->assertFalse($this->object->isValid([
            ['key' => 'rel', 'type' => Database::VAR_RELATIONSHIP],
        ]));
        $this->assertSame("Invalid type for attribute 'rel': relationship", $this->object->getDescription());
    }

    public function testResolveMatchesDedicatedEndpoints(): void
    {
        $this->assertEquals(
            ['type' => Database::VAR_TEXT, 'format' => '', 'size' => 65535],
            Attribute::resolve(['key' => 'body', 'type' => Database::VAR_TEXT])
        );

        $this->assertEquals(
            ['type' => Database::VAR_MEDIUMTEXT, 'format' => '', 'size' => 16777215],
            Attribute::resolve(['key' => 'body', 'type' => Database::VAR_MEDIUMTEXT])
        );

        $this->assertEquals(
            ['type' => Database::VAR_LONGTEXT, 'format' => '', 'size' => 2147483647],
            Attribute::resolve(['key' => 'body', 'type' => Database::VAR_LONGTEXT])
        );

        $this->assertEquals(
            ['type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 254],
            Attribute::resolve(['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL])
        );

        $this->assertEquals(
            ['type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_IP, 'size' => 39],
            Attribute::resolve(['key' => 'ip', 'type' => APP_DATABASE_ATTRIBUTE_IP])
        );

        $this->assertEquals(
            ['type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_URL, 'size' => 2000],
            Attribute::resolve(['key' => 'url', 'type' => APP_DATABASE_ATTRIBUTE_URL])
        );

        $this->assertEquals(
            ['type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_ENUM, 'size' => Database::LENGTH_KEY],
            Attribute::resolve(['key' => 'enum', 'type' => APP_DATABASE_ATTRIBUTE_ENUM])
        );

        // createIntegerColumn sizes the column off max, defaulting to the int64
        // range, and createBigIntColumn is always 8 bytes
        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER])
        );

        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'max' => 3000000000])
        );

        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 4],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'min' => 0, 'max' => 100])
        );

        // Both edges of the declared range have to be storable. A max on its own
        // leaves min at PHP_INT_MIN, and a min below INT32 needs the wide column
        // however small max is.
        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'max' => 100])
        );

        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'min' => -5000000000, 'max' => 100])
        );

        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 4],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'min' => -2147483648, 'max' => 2147483647])
        );

        $this->assertEquals(
            ['type' => Database::VAR_BIGINT, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'total', 'type' => Database::VAR_BIGINT])
        );

        $this->assertEquals(
            ['type' => Database::VAR_FLOAT, 'format' => '', 'size' => 0],
            Attribute::resolve(['key' => 'ratio', 'type' => Database::VAR_FLOAT])
        );

        // None of the numeric endpoints takes a size. A size sent inline must not
        // narrow the column below the range the same definition declares.
        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'size' => 4])
        );

        $this->assertEquals(
            ['type' => Database::VAR_INTEGER, 'format' => '', 'size' => 4],
            Attribute::resolve(['key' => 'counter', 'type' => Database::VAR_INTEGER, 'size' => 8, 'min' => 0, 'max' => 100])
        );

        $this->assertEquals(
            ['type' => Database::VAR_BIGINT, 'format' => '', 'size' => 8],
            Attribute::resolve(['key' => 'total', 'type' => Database::VAR_BIGINT, 'size' => 4])
        );

        $this->assertEquals(
            ['type' => Database::VAR_FLOAT, 'format' => '', 'size' => 0],
            Attribute::resolve(['key' => 'ratio', 'type' => Database::VAR_FLOAT, 'size' => 4])
        );
    }

    public function testNumericBoundsMustFitTheType(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'counter', 'type' => Database::VAR_INTEGER, 'min' => 0, 'max' => 100],
            ['key' => 'total', 'type' => Database::VAR_BIGINT, 'min' => \PHP_INT_MIN, 'max' => \PHP_INT_MAX],
            ['key' => 'ratio', 'type' => Database::VAR_FLOAT, 'min' => -1.5, 'max' => 1.5],
        ]), $this->object->getDescription());

        // 9223372036854776000 is what a client that rounds an int64 to a double
        // sends back after reading the default bounds off an existing column. PHP
        // decodes it as a float, and storing it leaves an integer column bounded
        // by 9.223372036854776e+18.
        $this->assertFalse($this->object->isValid([
            ['key' => 'counter', 'type' => Database::VAR_INTEGER, 'min' => -9223372036854776000, 'max' => 9223372036854776000],
        ]));
        $this->assertStringContainsString("Attribute 'counter': min is invalid", $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'counter', 'type' => Database::VAR_INTEGER, 'max' => 9223372036854776000],
        ]));
        $this->assertStringContainsString("Attribute 'counter': max is invalid", $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'total', 'type' => Database::VAR_BIGINT, 'max' => 9223372036854776000],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'counter', 'type' => Database::VAR_INTEGER, 'max' => 1.5],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'counter', 'type' => Database::VAR_INTEGER, 'max' => '100'],
        ]));

        // A float column carries the same bound as a double, which is what it is
        $this->assertTrue($this->object->isValid([
            ['key' => 'ratio', 'type' => Database::VAR_FLOAT, 'max' => 9223372036854776000],
        ]), $this->object->getDescription());
    }

    public function testResolveKeepsExplicitSize(): void
    {
        $this->assertEquals(
            ['type' => Database::VAR_STRING, 'format' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 512],
            Attribute::resolve(['key' => 'email', 'type' => APP_DATABASE_ATTRIBUTE_EMAIL, 'size' => 512])
        );

        $this->assertEquals(
            ['type' => Database::VAR_VARCHAR, 'format' => '', 'size' => 128],
            Attribute::resolve(['key' => 'slug', 'type' => Database::VAR_VARCHAR, 'size' => 128])
        );
    }
}
