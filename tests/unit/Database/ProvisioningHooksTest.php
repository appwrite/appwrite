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
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Query\Schema\ColumnType;

final class ProvisioningHooksTest extends TestCase
{
    private Factory $factory;

    private Document $project;

    protected function setUp(): void
    {
        $adapter = new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));

        $pools = new Group();
        $pools->add(new Pool(new Stack(), 'database_db_main', 1, static fn (): SQLite => $adapter, 1.0));

        $this->factory = new Factory($pools, new Cache(new MemoryCache()), new Authorization());
        $this->project = new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);
    }

    public function testACollectionCreatedWhileProvisioningIsListedToTheRolesItGrantsRead(): void
    {
        $provisioning = $this->factory->provisioning($this->project);
        $provisioning->create();
        $provisioning->createCollection($this->collection('provisioned', [Permission::read(Role::any())]));

        $project = $this->factory->project($this->project);
        $project->createCollection($this->collection('created', [Permission::read(Role::any())]));

        $listed = $project->find(Database::METADATA, [Query::equal('$id', ['created', 'provisioned'])]);
        $ids = \array_map(static fn (Document $collection): string => $collection->getId(), $listed);
        \sort($ids);

        $this->assertSame(
            ['created', 'provisioned'],
            $ids,
            'A collection created while provisioning a project must be listed to the roles it grants read, as one created through the project database is'
        );
    }

    /**
     * @param list<string> $permissions
     */
    private function collection(string $id, array $permissions): Collection
    {
        return new Collection(
            id: $id,
            attributes: [new Attribute('name', ColumnType::String, size: 255)],
            permissions: $permissions,
        );
    }
}
