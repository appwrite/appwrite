<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Attribute;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Query\Schema\ColumnType;

final class V20Test extends TestCase
{
    public function testConfiguredArrayAttributeIsRedeclaredAfterDroppingItsIndexes(): void
    {
        $database = new V20TestDatabase();

        (new V20TestMigration($database))->configured('teams', [
            Attribute::string(key: 'name', size: 128),
            Attribute::string(key: 'labels', size: 64, array: true),
        ], [
            Index::key('_key_name', ['name']),
            Index::key('_key_labels', ['labels']),
        ]);

        $this->assertSame([['teams', '_key_labels']], $database->deletedIndexes, 'only the indexes on the array attribute are dropped');
        $this->assertSame([['teams', 'labels', ColumnType::String]], $database->updatedAttributes);
    }

    public function testUserArrayAttributeIsRedeclaredWithItsStoredType(): void
    {
        $database = new V20TestDatabase();
        $migration = new V20TestMigration($database, [
            new Document(['$id' => 'index', 'key' => '_key_scores', 'attributes' => ['scores']]),
            new Document(['$id' => 'other', 'key' => '_key_title', 'attributes' => ['title']]),
        ]);

        $migration->user(new Document([
            'key' => 'scores',
            'type' => 'bigint',
            'array' => true,
            'databaseInternalId' => '1',
            'collectionInternalId' => '2',
        ]));

        $this->assertSame([['database_1_collection_2', '_key_scores']], $database->deletedIndexes);
        $this->assertSame([['database_1_collection_2', 'scores', ColumnType::BigInteger]], $database->updatedAttributes, 'the stored type string is read back as its column type');
    }
}
