<?php

namespace Utopia\Tests\Unit\Sources;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\Email;
use Utopia\Migration\Resources\Database\Columns\Enum;
use Utopia\Migration\Resources\Database\Columns\IP;
use Utopia\Migration\Resources\Database\Columns\LongText;
use Utopia\Migration\Resources\Database\Columns\MediumText;
use Utopia\Migration\Resources\Database\Columns\RegularText;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Columns\URL;
use Utopia\Migration\Resources\Database\Columns\Varchar;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Sources\Appwrite;

/**
 * Column parsing for the string type family. Appwrite reports no size for the
 * fixed width types (`text`, `mediumtext`, `longtext`) or for the strings that
 * are backed by a format (`email`, `url`, `ip`, `enum`) — the size is implied
 * — and it accepts a format as a type on an inline column definition. Either
 * shape has to come out of the source carrying the size the destination will
 * recreate the column with.
 */
class AppwriteColumnTest extends TestCase
{
    private Table $table;

    protected function setUp(): void
    {
        $this->table = new Table(new Database('main', 'Main'), 'Modules', 'modules');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return \array_merge([
            'key' => 'column',
            'required' => false,
            'default' => null,
            'array' => false,
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
        ], $overrides);
    }

    public function testFixedWidthTypesWithoutASize(): void
    {
        $text = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'modulePath',
            'type' => Column::TYPE_TEXT,
        ]));

        $this->assertInstanceOf(RegularText::class, $text);
        $this->assertSame(Column::TYPE_TEXT, $text->getType());
        $this->assertSame(65535, $text->getSize());

        $medium = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'summary',
            'type' => Column::TYPE_MEDIUMTEXT,
        ]));

        $this->assertInstanceOf(MediumText::class, $medium);
        $this->assertSame(Column::TYPE_MEDIUMTEXT, $medium->getType());
        $this->assertSame(16777215, $medium->getSize());

        $long = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'archive',
            'type' => Column::TYPE_LONGTEXT,
        ]));

        $this->assertInstanceOf(LongText::class, $long);
        $this->assertSame(Column::TYPE_LONGTEXT, $long->getType());
        $this->assertSame(2147483647, $long->getSize());
    }

    public function testVarcharKeepsItsSize(): void
    {
        $varchar = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'slug',
            'type' => Column::TYPE_VARCHAR,
            'size' => 64,
        ]));

        $this->assertInstanceOf(Varchar::class, $varchar);
        $this->assertSame(Column::TYPE_VARCHAR, $varchar->getType());
        $this->assertSame(64, $varchar->getSize());
    }

    public function testStringKeepsItsSize(): void
    {
        $string = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'title',
            'type' => Column::TYPE_STRING,
            'size' => 128,
            'format' => '',
        ]));

        $this->assertInstanceOf(Text::class, $string);
        $this->assertSame(Column::TYPE_STRING, $string->getType());
        $this->assertSame(128, $string->getSize());
    }

    public function testDatabaseDocumentKeepsStringSize(): void
    {
        $column = Appwrite::getColumn($this->table, new UtopiaDocument($this->payload([
            'key' => 'title',
            'type' => Column::TYPE_STRING,
            'size' => 128,
            'format' => '',
        ])));

        $this->assertSame(Text::class, $column::class);
        $this->assertSame(128, $column->getSize());
    }

    public function testFormattedStringsWithoutASize(): void
    {
        $email = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'email',
            'type' => Column::TYPE_STRING,
            'format' => Column::TYPE_EMAIL,
        ]));

        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(Column::TYPE_EMAIL, $email->getFormat());
        $this->assertSame(254, $email->getSize());

        $url = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'website',
            'type' => Column::TYPE_STRING,
            'format' => Column::TYPE_URL,
        ]));

        $this->assertInstanceOf(URL::class, $url);
        $this->assertSame(2000, $url->getSize());

        $ip = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'address',
            'type' => Column::TYPE_STRING,
            'format' => Column::TYPE_IP,
        ]));

        $this->assertInstanceOf(IP::class, $ip);
        $this->assertSame(39, $ip->getSize());

        $enum = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'status',
            'type' => Column::TYPE_STRING,
            'format' => Column::TYPE_ENUM,
            'elements' => ['on', 'off'],
        ]));

        $this->assertInstanceOf(Enum::class, $enum);
        $this->assertSame(UtopiaDatabase::LENGTH_KEY, $enum->getSize());
        $this->assertSame(['on', 'off'], $enum->getElements());
    }

    public function testFormatShorthandResolvesLikeTheFormattedString(): void
    {
        $shorthand = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'email',
            'type' => Column::TYPE_EMAIL,
        ]));

        $formatted = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'email',
            'type' => Column::TYPE_STRING,
            'format' => Column::TYPE_EMAIL,
        ]));

        $this->assertEquals($formatted->jsonSerialize(), $shorthand->jsonSerialize());
    }

    public function testFormatShorthandUsedAsAType(): void
    {
        $email = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'email',
            'type' => Column::TYPE_EMAIL,
        ]));

        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(Column::TYPE_EMAIL, $email->getFormat());
        $this->assertSame(254, $email->getSize());

        $url = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'website',
            'type' => Column::TYPE_URL,
        ]));

        $this->assertInstanceOf(URL::class, $url);
        $this->assertSame(Column::TYPE_URL, $url->getFormat());
        $this->assertSame(2000, $url->getSize());

        $ip = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'address',
            'type' => Column::TYPE_IP,
        ]));

        $this->assertInstanceOf(IP::class, $ip);
        $this->assertSame(Column::TYPE_IP, $ip->getFormat());
        $this->assertSame(39, $ip->getSize());

        $enum = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'status',
            'type' => Column::TYPE_ENUM,
            'elements' => ['on', 'off'],
            'default' => 'on',
        ]));

        $this->assertInstanceOf(Enum::class, $enum);
        $this->assertSame(Column::TYPE_ENUM, $enum->getFormat());
        $this->assertSame(UtopiaDatabase::LENGTH_KEY, $enum->getSize());
        $this->assertSame('on', $enum->getDefault());
    }

    public function testDerivedSizeSurvivesTheAttributeConversion(): void
    {
        $attribute = Appwrite::getColumn($this->table, $this->payload([
            'key' => 'modulePath',
            'type' => Column::TYPE_TEXT,
        ]))->getAttribute();

        $this->assertSame(Column::TYPE_TEXT, $attribute->getType());
        $this->assertSame(65535, $attribute->getSize());
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported column type: blob');

        Appwrite::getColumn($this->table, $this->payload([
            'key' => 'unknown',
            'type' => 'blob',
        ]));
    }
}
