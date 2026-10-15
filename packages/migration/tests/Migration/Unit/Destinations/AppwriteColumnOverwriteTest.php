<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\Structure;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\Email;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Query\Schema\ColumnType;
use Utopia\Validator\Text as TextValidator;

final class AppwriteColumnOverwriteTest extends TestCase
{
    use TransfersColumns;

    private const string OLDER_UPDATED_AT = '2020-01-01T00:00:00.000+00:00';

    private const string NEWER_UPDATED_AT = '2030-01-01T00:00:00.000+00:00';

    private const string EMAIL_FORMAT = 'email';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
        Structure::addFormat(self::EMAIL_FORMAT, static fn (): TextValidator => new TextValidator(0), ColumnType::String);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Structure::removeFormat(self::EMAIL_FORMAT);
        parent::tearDown();
    }

    public function testAnOverwriteWithANullSourceDefaultClearsTheDestinationDefault(): void
    {
        $database = $this->projectDatabase();
        [, $first] = $this->transferColumn(
            static fn (Table $table): Column => new Text('title', $table, default: 'hello', size: 100),
            $database,
            OnDuplicate::Fail,
            self::OLDER_UPDATED_AT,
        );
        $this->assertSame([], $this->errorMessages($first));
        $this->assertSame('hello', $this->physicalColumn($database, 'title')->default);

        [, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new Text('title', $table, default: null, size: 120),
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SUCCESS, $column->getStatus());

        $physical = $this->physicalColumn($database, 'title');
        $this->assertNull($physical->default, 'A null source default must clear the physical column default.');
        $this->assertSame(120, $physical->size, 'The overwrite must resize the physical column.');
        $this->assertNull(
            $this->attributeDocument($database)->getAttribute('default'),
            'The column metadata row must no longer carry the old default.',
        );
    }

    public function testAnOverwriteOfAFormattedColumnKeepsItsFormat(): void
    {
        $database = $this->projectDatabase();
        [, $first] = $this->transferColumn(
            static fn (Table $table): Column => new Email('mail', $table, size: 254),
            $database,
            OnDuplicate::Fail,
            self::OLDER_UPDATED_AT,
        );
        $this->assertSame([], $this->errorMessages($first));
        $this->assertSame(self::EMAIL_FORMAT, $this->physicalColumn($database, 'mail')->format?->name);

        [, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new Email('mail', $table, required: true, size: 254),
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SUCCESS, $column->getStatus());

        $physical = $this->physicalColumn($database, 'mail');
        $this->assertTrue($physical->required, 'The overwrite must make the physical column required.');
        $this->assertSame(
            self::EMAIL_FORMAT,
            $physical->format?->name,
            'An in-place overwrite must keep the physical column format.',
        );

        $metadata = $this->attributeDocument($database);
        $this->assertTrue($metadata->getAttribute('required'));
        $this->assertSame(self::EMAIL_FORMAT, $metadata->getAttribute('format'));
    }
}
