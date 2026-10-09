<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V16;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class V16Test extends TestCase
{
    public function testMigratesCollectionsOfTheProjectCollectionType(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('_16');
        $database->create();

        $database->createCollection(Collection::create(
            id: 'sessions',
            attributes: [
                Attribute::string(key: 'userId'),
                Attribute::datetime(key: 'expire'),
            ],
        ));

        $migration = new class ($database) extends V16 {
            public function __construct(Database $database)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '16']);
                $this->collections = [
                    'console' => [],
                    'projects' => [
                        'sessions' => ['$id' => 'sessions', '$collection' => Database::METADATA],
                    ],
                ];
            }

            public function migrateProjectCollections(): void
            {
                $this->migrateCollections();
            }
        };

        \ob_start();
        try {
            $migration->migrateProjectCollections();
        } finally {
            \ob_end_clean();
        }

        $keys = \array_map(
            fn (Attribute $attribute): string => $attribute->key,
            $database->getCollection('sessions')->attributes(),
        );
        $this->assertSame(['userId'], $keys, 'sessions.expire must be dropped when migrating the project collection map');
    }
}
