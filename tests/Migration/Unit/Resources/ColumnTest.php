<?php

namespace Utopia\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Migration\Resources\Database\Column;

/**
 * Lock-in for Column::resolve(), which mirrors the type/format/size mapping
 * Appwrite applies in Appwrite\Utopia\Database\Attribute. If the two drift, a
 * migrated column lands on the destination with a different size than the one
 * the source project created it with.
 */
class ColumnTest extends TestCase
{
    public function testFixedWidthTypesDeriveTheirSize(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_TEXT, 'format' => '', 'size' => 65535],
            Column::resolve(['key' => 'body', 'type' => Column::TYPE_TEXT]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_MEDIUMTEXT, 'format' => '', 'size' => 16777215],
            Column::resolve(['key' => 'summary', 'type' => Column::TYPE_MEDIUMTEXT]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_LONGTEXT, 'format' => '', 'size' => 2147483647],
            Column::resolve(['key' => 'archive', 'type' => Column::TYPE_LONGTEXT]),
        );
    }

    public function testFixedWidthTypesIgnoreAReportedSize(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_TEXT, 'format' => '', 'size' => 65535],
            Column::resolve(['key' => 'body', 'type' => Column::TYPE_TEXT, 'size' => 128]),
        );
    }

    public function testFormatShorthandsBecomeAString(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_EMAIL, 'size' => 254],
            Column::resolve(['key' => 'email', 'type' => Column::TYPE_EMAIL]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_URL, 'size' => 2000],
            Column::resolve(['key' => 'website', 'type' => Column::TYPE_URL]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_IP, 'size' => 39],
            Column::resolve(['key' => 'address', 'type' => Column::TYPE_IP]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_ENUM, 'size' => UtopiaDatabase::LENGTH_KEY],
            Column::resolve(['key' => 'status', 'type' => Column::TYPE_ENUM]),
        );
    }

    public function testFormattedStringWithoutSizeFallsBackToTheFormatSize(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_EMAIL, 'size' => 254],
            Column::resolve([
                'key' => 'email',
                'type' => Column::TYPE_STRING,
                'format' => Column::TYPE_EMAIL,
            ]),
        );
    }

    public function testExplicitSizeWins(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_STRING, 'format' => Column::TYPE_EMAIL, 'size' => 512],
            Column::resolve(['key' => 'email', 'type' => Column::TYPE_EMAIL, 'size' => 512]),
        );

        $this->assertSame(
            ['type' => Column::TYPE_VARCHAR, 'format' => '', 'size' => 64],
            Column::resolve(['key' => 'slug', 'type' => Column::TYPE_VARCHAR, 'size' => 64]),
        );

        // A size that survived a round trip through a string stays a size.
        $this->assertSame(
            ['type' => Column::TYPE_VARCHAR, 'format' => '', 'size' => 64],
            Column::resolve(['key' => 'slug', 'type' => Column::TYPE_VARCHAR, 'size' => '64']),
        );
    }

    public function testUnsizedAndUnknownDefinitionsResolveToZero(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_VARCHAR, 'format' => '', 'size' => 0],
            Column::resolve(['key' => 'slug', 'type' => Column::TYPE_VARCHAR]),
        );

        $this->assertSame(
            ['type' => '', 'format' => '', 'size' => 0],
            Column::resolve(['key' => 'unknown']),
        );
    }

    public function testQueryLibBigIntegerAliasBecomesBigint(): void
    {
        $this->assertSame(
            ['type' => Column::TYPE_BIG_INT, 'format' => '', 'size' => 0],
            Column::resolve(['key' => 'total', 'type' => 'biginteger']),
        );
    }
}
