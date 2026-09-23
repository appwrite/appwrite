<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Capability;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Query\Schema\ColumnType;

final class BootDatabaseTest extends TestCase
{
    private const string HOSTNAME = 'database_db_main';

    private const string NAMESPACE = 'shared';

    private const array VARIABLES = [
        '_APP_DATABASE_SHARED_NAMESPACE',
        '_APP_DATABASE_SHARED_TABLES',
    ];

    /** @var array<string, string|false> */
    private array $variables = [];

    private Factory $factory;

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->variables[$variable] = \getenv($variable);
        }

        \putenv('_APP_DATABASE_SHARED_NAMESPACE=' . self::NAMESPACE);
        \putenv('_APP_DATABASE_SHARED_TABLES=' . self::HOSTNAME);

        $connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Reports the host it dialled, as a pooled MariaDB, MySQL, PostgreSQL or
        // MongoDB connection does; plain SQLite keys its cache by no host at all.
        $adapter = new class ($connection) extends SQLite {
            public function supports(Capability $feature): bool
            {
                return $feature === Capability::Hostname || parent::supports($feature);
            }

            public function getHostname(): string
            {
                return 'mariadb';
            }
        };

        $pools = new Group();
        $pools->add(new Pool(new Stack(), self::HOSTNAME, 1, static fn (): SQLite => $adapter, 1.0));

        $this->factory = new Factory($pools, new Cache(new MemoryCache()), new Authorization());
    }

    protected function tearDown(): void
    {
        foreach ($this->variables as $variable => $value) {
            \putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    public function testACollectionCreatedAtBootIsVisibleToAProjectReadThatCachedItsAbsence(): void
    {
        $setup = $this->factory->setup(self::HOSTNAME);
        $setup->create();

        $project = $this->factory->project($this->project());

        $this->assertTrue($project->getCollection('users')->isEmpty());

        $setup->createCollection($this->collection('users'));

        $this->assertFalse(
            $project->getCollection('users')->isEmpty(),
            'A project database that read a project collection before the boot created it must see it once the boot has'
        );
    }

    private function project(): Document
    {
        return new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://' . self::HOSTNAME . '?namespace=' . self::NAMESPACE,
        ]);
    }

    private function collection(string $id): Collection
    {
        return new Collection(
            id: $id,
            attributes: [new Attribute('name', ColumnType::String, size: 255)],
        );
    }
}
