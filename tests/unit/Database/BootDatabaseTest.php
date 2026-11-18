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
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

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

        $adapter = new class ($connection) extends SQLite {
            #[\Override]
            public function hostname(): string
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

        $this->assertNull($project->findCollection('users'));

        $setup->createCollection($this->collection('users'));

        $this->assertNotNull(
            $project->findCollection('users'),
            'A project database that read a project collection before the boot created it must see it once the boot has'
        );
    }

    public function testAProjectCollectionCreatedAtBootIsListedToTheRolesItGrantsRead(): void
    {
        $setup = $this->factory->setup(self::HOSTNAME);
        $setup->create();
        $setup->createCollection($this->collection('booted', [Permission::read(Role::any())]));

        $project = $this->factory->project($this->project());
        $project->createCollection($this->collection('created', [Permission::read(Role::any())]));

        $listed = $project->find(Database::METADATA, [Query::equal('$id', ['booted', 'created'])]);

        $ids = \array_map(static fn (Document $collection): string => $collection->getId(), $listed);
        \sort($ids);

        $this->assertSame(
            ['booted', 'created'],
            $ids,
            'A project collection the boot creates must be listed to the roles it grants read, as one a project database creates is'
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

    /**
     * @param list<string>|null $permissions
     */
    private function collection(string $id, ?array $permissions = null): Collection
    {
        return Collection::create(
            id: $id,
            attributes: [Attribute::string('name', size: 255)],
            permissions: $permissions,
        );
    }
}
