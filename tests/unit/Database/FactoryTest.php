<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Factory;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utopia\Database\Adapter\ConnectedMemory;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

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
        $this->assertNull($read->findCollection('targets'));

        $provisioning->createCollection(Collection::create(
            id: 'targets',
            attributes: [Attribute::string('userInternalId', size: 255)],
        ));

        $this->assertNotNull(
            $read->findCollection('targets'),
            'Provisioning creates the collections a project database later reads, so its cache invalidation has to reach that reader'
        );
    }

    public function testFactoryPreservesTheSuppliedCacheNamespace(): void
    {
        $cache = new Cache(new MemoryCache());
        $cache->save('setting', 'unprefixed');
        $cache->save('v2:setting', 'warm');
        $database = $this->factory($cache->withNamespace('v2:'))->project($this->project());

        $this->assertSame('warm', $database->getCache()->load('setting', 60));
        $database->getCache()->save('setting', 'updated');
        $this->assertSame('updated', $cache->load('v2:setting', 60));
        $this->assertSame('unprefixed', $cache->load('setting', 60));
    }

    public function testFactoryKeepsOrdinaryUnprefixedCacheEntriesWarm(): void
    {
        $cache = new Cache(new MemoryCache());
        $cache->save('setting', 'warm');
        $database = $this->factory($cache)->project($this->project());

        $this->assertSame('warm', $database->getCache()->load('setting', 60));
        $database->getCache()->save('setting', 'updated');
        $this->assertSame('updated', $cache->load('setting', 60));
    }

    private function factory(?Cache $cache = null): Factory
    {
        $adapter = (new ConnectedMemory())->setHostname('mariadb');
        $pools = new Group();
        $pools->add(new Pool(new Stack(), 'database_db_main', 1, static fn (): ConnectedMemory => $adapter, 1.0));

        return new Factory($pools, $cache ?? new Cache(new MemoryCache()), new Authorization());
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
