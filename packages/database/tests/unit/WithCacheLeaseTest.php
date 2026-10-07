<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter;
use Utopia\Cache\Cache;
use Utopia\Cache\Feature\Leasable;
use Utopia\Database\Adapter\Memory as DatabaseMemory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class WithCacheLeaseTest extends TestCase
{
    private LeasableMemoryCache $cacheAdapter;

    private Database $database;

    private string $key;

    protected function setUp(): void
    {
        $this->cacheAdapter = new LeasableMemoryCache();
        $this->database = new Database(new DatabaseMemory(), new Cache($this->cacheAdapter));
        $this->database
            ->setDatabase('utopiaTests')
            ->setNamespace('with_cache_' . \uniqid());

        $this->database->create();
        $this->database->createCollection('projects');
        $this->database->createAttribute('projects', 'name', Database::VAR_STRING, 255, false);
        $this->database->createDocument('projects', new Document([
            '$id' => 'project',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'fresh',
        ]));

        $this->key = $this->database->getQueryCacheKey('projects');
    }

    public function testDocumentPurgeRemovesAllVariantsAndRejectsStaleWrites(): void
    {
        [$collectionKey, $documentKey, $firstHash] = $this->database->getCacheKeys('projects', 'project');
        [, , $secondHash] = $this->database->getCacheKeys('projects', 'project', ['name']);
        $this->cacheAdapter->save($collectionKey, 'legacy', $documentKey);
        $this->cacheAdapter->save($documentKey, 'first', $firstHash);
        $this->cacheAdapter->save($documentKey, 'second', $secondHash);
        $lease = $this->cacheAdapter->getGeneration($documentKey);

        $this->assertTrue($this->database->purgeCachedDocument('projects', 'project'));

        $this->assertFalse($this->cacheAdapter->load($collectionKey, Database::TTL, $documentKey));
        $this->assertFalse($this->cacheAdapter->load($documentKey, Database::TTL, $firstHash));
        $this->assertFalse($this->cacheAdapter->load($documentKey, Database::TTL, $secondHash));
        $this->cacheAdapter->saveWithLease($documentKey, 'stale', $firstHash, $lease);
        $this->assertFalse($this->cacheAdapter->load($documentKey, Database::TTL, $firstHash));
        $this->assertSame('fresh', $this->database->getDocument('projects', 'project')->getAttribute('name'));
    }

    public function testStaleListWriteAfterConcurrentPurgeIsRejected(): void
    {
        $hash = 'list-hash';

        $document = $this->database->getDocument('projects', 'project');

        // The callback stands in for the database read of an older request: a
        // concurrent writer purges the query key after the read started but
        // before the result is cached. Without a lease the stale list below
        // would land in the cache after the purge.
        $result = $this->database->withCache($this->key, function () use ($hash, $document) {
            $this->cacheAdapter->purge($this->key, $hash);

            return [$document];
        }, $hash);

        $this->assertCount(1, $result);
        $this->assertFalse(
            $this->cacheAdapter->load($this->key, Database::TTL, $hash),
            'A list read whose query key was purged mid-flight must not be re-cached.'
        );
    }

    public function testListWriteLandsWhenNoConcurrentPurge(): void
    {
        $hash = 'list-hash';

        $document = $this->database->getDocument('projects', 'project');

        $result = $this->database->withCache($this->key, fn () => [$document], $hash);

        $this->assertCount(1, $result);
        $this->assertNotFalse(
            $this->cacheAdapter->load($this->key, Database::TTL, $hash),
            'A list read with no concurrent purge must populate the cache.'
        );
    }
}

class LeasableMemoryCache implements Adapter, Leasable
{
    private const string GENERATION_FIELD = '__utopia_gen__';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $store = [];

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if ($hash === '') {
            $hash = $key;
        }

        if (! isset($this->store[$key][$hash])) {
            return false;
        }

        $saved = $this->store[$key][$hash];

        return ($saved['time'] + $ttl > \time()) ? $saved['data'] : false;
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if ($hash === '') {
            $hash = $key;
        }

        if ($hash === self::GENERATION_FIELD) {
            return false;
        }

        $this->store[$key][$hash] = ['time' => \time(), 'data' => $data];

        return $data;
    }

    public function getGeneration(string $key): string
    {
        return $this->store[$key][self::GENERATION_FIELD]['data'] ?? '0';
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        if ($this->getGeneration($key) !== $generation) {
            return false;
        }

        return $this->save($key, $data, $hash);
    }

    public function touch(string $key, string $hash = ''): bool
    {
        if ($hash === '') {
            $hash = $key;
        }

        if (! isset($this->store[$key][$hash])) {
            return false;
        }

        $this->store[$key][$hash]['time'] = \time();

        return true;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return \array_values(\array_filter(
            \array_keys($this->store[$key] ?? []),
            fn (string $field): bool => $field !== self::GENERATION_FIELD
        ));
    }

    public function purge(string $key, string $hash = ''): bool
    {
        $generation = (string) (((int) $this->getGeneration($key)) + 1);

        if ($hash !== '' && $hash !== self::GENERATION_FIELD) {
            unset($this->store[$key][$hash]);
        } else {
            $this->store[$key] = [];
        }

        $this->store[$key][self::GENERATION_FIELD] = ['time' => \time(), 'data' => $generation];

        return true;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return \count($this->store);
    }

    public function getName(?string $key = null): string
    {
        return 'leasable-memory';
    }
}
