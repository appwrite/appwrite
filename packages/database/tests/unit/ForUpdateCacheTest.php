<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as DatabaseMemory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class ForUpdateCacheTest extends TestCase
{
    private DatabaseMemory $adapter;

    private Database $database;

    protected function setUp(): void
    {
        $this->adapter = new DatabaseMemory();
        $this->database = new Database($this->adapter, new Cache(new CacheMemory()));
        $this->database
            ->setDatabase('utopiaTests')
            ->setNamespace('for_update_' . \uniqid());

        $this->database->create();
        $this->database->createCollection('projects');
        $this->database->createAttribute('projects', 'name', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('projects', 'description', Database::VAR_STRING, 255, false);
        $this->database->createDocument('projects', new Document([
            '$id' => 'project',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'stale',
            'description' => 'stale',
        ]));
    }

    /**
     * Write to the row through the adapter, bypassing Database and therefore
     * the cache purge — the cache now holds a stale copy, exactly as if a
     * concurrent reader had re-cached the old row after a writer's purge.
     */
    private function staleCache(string $attribute, string $value): void
    {
        $collection = $this->database->getCollection('projects');
        $document = $this->adapter->getDocument($collection, 'project');
        $document->setAttribute($attribute, $value);
        $this->adapter->updateDocument($collection, 'project', $document, true);
    }

    public function testPurgeMakesTheNextReadReturnFreshData(): void
    {
        $this->assertSame('stale', $this->database->getDocument('projects', 'project')->getAttribute('name'));
        $this->staleCache('name', 'fresh');
        $this->assertSame('stale', $this->database->getDocument('projects', 'project')->getAttribute('name'));

        $this->database->purgeCachedDocument('projects', 'project');

        $this->assertSame('fresh', $this->database->getDocument('projects', 'project')->getAttribute('name'));
    }

    public function testForUpdateReadBypassesStaleCache(): void
    {
        $cached = $this->database->getDocument('projects', 'project');
        $this->assertSame('stale', $cached->getAttribute('name'));

        $this->staleCache('name', 'fresh');

        $cached = $this->database->getDocument('projects', 'project');
        $this->assertSame('stale', $cached->getAttribute('name'));

        $fresh = $this->database->getDocument('projects', 'project', forUpdate: true);
        $this->assertSame('fresh', $fresh->getAttribute('name'));
    }

    public function testForUpdateReadDoesNotPopulateCache(): void
    {
        $this->database->getDocument('projects', 'project');
        $this->staleCache('name', 'fresh');

        $this->database->getDocument('projects', 'project', forUpdate: true);

        // The locking read happens inside an open transaction; caching what it
        // saw would publish a pre-commit row. The cached copy must be untouched.
        $cached = $this->database->getDocument('projects', 'project');
        $this->assertSame('stale', $cached->getAttribute('name'));
    }

    public function testUpdateDocumentDoesNotResurrectStaleCachedAttributes(): void
    {
        $this->database->getDocument('projects', 'project');
        $this->staleCache('name', 'fresh');

        // updateDocument merges the changes into its locking read of the current
        // row. Served from the stale cache, that merge would durably revert
        // name to 'stale' — the lost-update behind flaky project config tests.
        $this->database->updateDocument('projects', 'project', new Document([
            'description' => 'updated',
        ]));

        $collection = $this->database->getCollection('projects');
        $row = $this->adapter->getDocument($collection, 'project');
        $this->assertSame('fresh', $row->getAttribute('name'));
        $this->assertSame('updated', $row->getAttribute('description'));

        $document = $this->database->getDocument('projects', 'project');
        $this->assertSame('fresh', $document->getAttribute('name'));
        $this->assertSame('updated', $document->getAttribute('description'));
    }
}
