<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Factory;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Capability;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

final class FactoryTest extends TestCase
{
    public function testProvisioningAndProjectAgreeOnTheCollectionCacheKey(): void
    {
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);

        $factory = $this->factory('database_db_main');

        $provisioning = $factory->provisioning($project)->getCacheBaseKeys(Database::METADATA, 'targets');
        $read = $factory->project($project)->getCacheBaseKeys(Database::METADATA, 'targets');

        $this->assertSame(
            $read,
            $provisioning,
            'Provisioning creates the collections the project database later reads, so both must resolve one cache key or an invalidation lands where nobody looks'
        );
    }

    public function testTheCollectionCacheKeyIsScopedToThePoolHostname(): void
    {
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);

        [$collectionKey] = $this->factory('database_db_main')
            ->provisioning($project)
            ->getCacheBaseKeys(Database::METADATA, 'targets');

        $this->assertStringContainsString('database_db_main', $collectionKey);
        $this->assertStringNotContainsString(ConnectedAdapter::HOSTNAME, $collectionKey);
    }

    private function factory(string $pool): Factory
    {
        $pools = new Group();
        $pools->add(new Pool(new Stack(), $pool, 1, static fn (): ConnectedAdapter => new ConnectedAdapter(), 1.0));

        return new Factory($pools, new Cache(new NoCache()), new Authorization());
    }
}

/**
 * Reports a hostname of its own, the way a pooled SQL connection reports the
 * host it dialled rather than the pool it came from.
 */
final class ConnectedAdapter extends Memory
{
    public const string HOSTNAME = 'mariadb';

    public function supports(Capability $feature): bool
    {
        return $feature === Capability::Hostname || parent::supports($feature);
    }

    public function getHostname(): string
    {
        return self::HOSTNAME;
    }
}
