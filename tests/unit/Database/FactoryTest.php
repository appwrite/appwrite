<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Factory;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Capability;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Query\Schema\ColumnType;

final class FactoryTest extends TestCase
{
    public function testACollectionCreatedByProvisioningIsVisibleToAProjectRead(): void
    {
        $factory = $this->factory();
        $project = $this->project();

        $read = $factory->project($project);
        $provisioning = $factory->provisioning($project);
        $provisioning->create();

        // The maintenance sweep reads a collection that provisioning has not
        // created yet, which caches its absence.
        $this->assertTrue($read->getCollection('targets')->isEmpty());

        $provisioning->createCollection(new Collection(
            id: 'targets',
            attributes: [new Attribute('userInternalId', ColumnType::String, size: 255)],
        ));

        $this->assertFalse(
            $read->getCollection('targets')->isEmpty(),
            'Provisioning creates the collections a project database later reads, so its cache invalidation has to reach that reader'
        );
    }

    private function factory(): Factory
    {
        $adapter = new ConnectedAdapter();
        $pools = new Group();
        $pools->add(new Pool(new Stack(), 'database_db_main', 1, static fn (): ConnectedAdapter => $adapter, 1.0));

        return new Factory($pools, new Cache(new MemoryCache()), new Authorization());
    }

    private function project(): Document
    {
        return new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);
    }
}

/**
 * Reports a hostname of its own, the way a pooled SQL connection reports the
 * host it dialled rather than the pool it was taken from. Without it the cache
 * key carries no hostname at all and the two paths cannot disagree.
 */
final class ConnectedAdapter extends Memory
{
    public function supports(Capability $feature): bool
    {
        return $feature === Capability::Hostname || parent::supports($feature);
    }

    public function getHostname(): string
    {
        return 'mariadb';
    }
}
