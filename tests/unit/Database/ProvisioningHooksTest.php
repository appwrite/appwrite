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
use Utopia\Database\Storage;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Query\Schema\ColumnType;

final class ProvisioningHooksTest extends TestCase
{
    private PDO $connection;

    private Factory $factory;

    private Document $project;

    protected function setUp(): void
    {
        $this->connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter = new SQLite($this->connection);

        $pools = new Group();
        $pools->add(new Pool(new Stack(), 'database_db_main', 1, static fn (): SQLite => $adapter, 1.0));

        $this->factory = new Factory($pools, new Cache(new MemoryCache()), new Authorization());
        $this->project = new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);
    }

    public function testProvisioningRegistersThePermissionHookAProjectDatabaseHas(): void
    {
        $this->assertTrue($this->factory->project($this->project)->getAdapter()->hasPermissionHook());
        $this->assertTrue(
            $this->factory->provisioning($this->project)->getAdapter()->hasPermissionHook(),
            'The database that creates a new project\'s collections must maintain the permission side table like every other database the factory builds'
        );
    }

    public function testACollectionCreatedWhileProvisioningKeepsItsPermissionRows(): void
    {
        $provisioning = $this->factory->provisioning($this->project);
        $provisioning->create();
        $provisioning->createCollection($this->collection('provisioned'));

        $this->factory->project($this->project)->createCollection($this->collection('created'));

        $this->assertSame([['create', 'any']], $this->permissionRows($provisioning, 'created'));
        $this->assertSame(
            $this->permissionRows($provisioning, 'created'),
            $this->permissionRows($provisioning, 'provisioned'),
            'A collection created while provisioning a project must store the same permission rows as one created through the project database'
        );
    }

    private function collection(string $id): Collection
    {
        return new Collection(
            id: $id,
            attributes: [new Attribute('name', ColumnType::String, size: 255)],
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function permissionRows(Database $database, string $collection): array
    {
        $table = $database->getNamespace() . '_' . Storage::permissionsTable(Database::METADATA);
        $statement = $this->connection->prepare("SELECT _type, _permission FROM `{$table}` WHERE _document = :document ORDER BY _id");
        $statement->execute(['document' => $collection]);

        /** @var list<array{0: string, 1: string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_NUM);

        return $rows;
    }
}
