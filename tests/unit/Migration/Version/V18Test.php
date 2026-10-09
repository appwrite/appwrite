<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V18;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class V18Test extends TestCase
{
    public function testChangesDoubleAttributesOfTheProjectCollectionType(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('_18');
        $database->create();

        $attributes = [
            Attribute::string(key: 'name'),
            Attribute::double(key: 'value'),
        ];

        $database->createCollection(Collection::create(id: 'stats', attributes: $attributes));

        $migration = new class ($database, $attributes) extends V18 {
            /**
             * @var list<array{string, string, string}>
             */
            public array $changes = [];

            /**
             * @param array<Attribute> $attributes
             */
            public function __construct(Database $database, array $attributes)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '18']);
                $this->collections = [
                    'console' => [
                        'consoleStats' => ['$id' => 'consoleStats', '$collection' => Database::METADATA, 'attributes' => $attributes],
                    ],
                    'projects' => [
                        'stats' => ['$id' => 'stats', '$collection' => Database::METADATA, 'attributes' => $attributes],
                    ],
                ];
            }

            protected function changeAttributeInternalType(string $collection, string $attribute, string $type): void
            {
                $this->changes[] = [$collection, $attribute, $type];
            }

            public function migrateProjectCollections(): void
            {
                \Closure::bind(fn () => $this->migrateCollections(), $this, V18::class)();
            }
        };

        \ob_start();
        try {
            $migration->migrateProjectCollections();
        } finally {
            \ob_end_clean();
        }

        $this->assertSame([['stats', 'value', 'DOUBLE']], $migration->changes);
        $this->assertTrue($database->getCollection('stats')->documentSecurity());
    }
}
