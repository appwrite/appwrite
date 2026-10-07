<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as DatabaseMemory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class QueryCacheTest extends TestCase
{
    /**
     * @param array<string, array{encode: callable, decode: callable}> $filters
     */
    private function createDatabase(Adapter $cache, array $filters = [], ?DatabaseMemory $adapter = null): Database
    {
        $database = new Database($adapter ?? new DatabaseMemory(), new Cache($cache), $filters);
        $database
            ->setDatabase('utopiaTests')
            ->setNamespace('list_cache_' . \uniqid());

        $database->create();

        return $database;
    }

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function findWithCache(
        Database $database,
        string $collection,
        array $queries = [],
        ?string $namespace = null,
    ): array {
        foreach ($queries as $query) {
            if ($query instanceof Query && $query->getMethod() === Query::TYPE_ORDER_RANDOM) {
                return $database->find($collection, $queries);
            }
        }

        $collectionDocument = $database->getCollection($collection);
        $cacheKey = $database->getQueryCacheKey($collectionDocument->getId(), $namespace);
        $cacheHash = $database->getQueryCacheField($collectionDocument, $queries);

        return $database->withCache(
            key: $cacheKey,
            callback: fn (): array => $database->find($collection, $queries),
            hash: $cacheHash,
        );
    }

    public function testWithCacheUsesCallbackOnMissAndCachesResult(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): array {
                $callbackCalls++;
                return ['value' => 'fresh'];
            },
        );

        $this->assertSame(['value' => 'fresh'], $value);
        $this->assertSame(1, $callbackCalls);

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): array {
                $callbackCalls++;
                return ['value' => 'new'];
            },
        );

        $this->assertSame(['value' => 'fresh'], $value);
        $this->assertSame(1, $callbackCalls);
    }

    public function testWithCacheCachesEmptyValues(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): array {
                $callbackCalls++;
                return [];
            },
        );

        $this->assertSame([], $value);

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): array {
                $callbackCalls++;
                return ['value' => 'miss'];
            },
        );

        $this->assertSame([], $value);
        $this->assertSame(1, $callbackCalls);
    }

    public function testWithCacheCachesNullValues(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): mixed {
                $callbackCalls++;
                return null;
            },
        );

        $this->assertNull($value);

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): string {
                $callbackCalls++;
                return 'miss';
            },
        );

        $this->assertNull($value);
        $this->assertSame(1, $callbackCalls);
    }

    public function testWithCacheSeparatesPayloadsByHashField(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $firstCalls = 0;
        $secondCalls = 0;

        $first = $database->withCache(
            'key',
            function () use (&$firstCalls): array {
                $firstCalls++;
                return ['value' => 'first'];
            },
            'first-field',
        );

        $second = $database->withCache(
            'key',
            function () use (&$secondCalls): array {
                $secondCalls++;
                return ['value' => 'second'];
            },
            'second-field',
        );

        $cachedFirst = $database->withCache(
            'key',
            function () use (&$firstCalls): array {
                $firstCalls++;
                return ['value' => 'miss'];
            },
            'first-field',
        );

        $this->assertSame(['value' => 'first'], $first);
        $this->assertSame(['value' => 'second'], $second);
        $this->assertSame(['value' => 'first'], $cachedFirst);
        $this->assertSame(1, $firstCalls);
        $this->assertSame(1, $secondCalls);
        $this->assertSame(['first-field', 'second-field'], $cache->list('key'));
    }

    public function testWithCacheDoesNotCacheFalseValues(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): bool {
                $callbackCalls++;
                return false;
            },
        );

        $this->assertFalse($value);
        $this->assertSame([], $cache->list('key'));

        $value = $database->withCache(
            'key',
            function () use (&$callbackCalls): string {
                $callbackCalls++;
                return 'fresh';
            },
        );

        $this->assertSame('fresh', $value);
        $this->assertSame(2, $callbackCalls);
    }

    public function testWithCacheBypassesCacheForNullHash(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $first = $database->withCache(
            key: 'key',
            callback: function () use (&$callbackCalls): string {
                $callbackCalls++;
                return 'first';
            },
            hash: null,
        );
        $second = $database->withCache(
            key: 'key',
            callback: function () use (&$callbackCalls): string {
                $callbackCalls++;
                return 'second';
            },
            hash: null,
        );

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
        $this->assertSame(2, $callbackCalls);
        $this->assertSame([], $cache->list('key'));
    }

    public function testWithCacheDoesNotCacheCollectionlessDocuments(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);

        $callbackCalls = 0;

        $first = $database->withCache(
            key: 'key',
            callback: function () use (&$callbackCalls): Document {
                $callbackCalls++;
                return new Document(['$id' => 'first']);
            },
        );
        $second = $database->withCache(
            key: 'key',
            callback: function () use (&$callbackCalls): Document {
                $callbackCalls++;
                return new Document(['$id' => 'second']);
            },
        );

        $this->assertSame('first', $first->getId());
        $this->assertSame('second', $second->getId());
        $this->assertSame(2, $callbackCalls);
        $this->assertSame([], $cache->list('key'));
    }

    public function testWithCacheDoesNotCacheMixedDocumentArrays(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
        ]));

        $callbackCalls = 0;

        $first = $database->withCache(
            key: 'key',
            callback: function () use ($database, &$callbackCalls): array {
                $callbackCalls++;
                return ['prefix', $database->getDocument('wafRules', 'rule-a')];
            },
        );
        $second = $database->withCache(
            key: 'key',
            callback: function () use ($database, &$callbackCalls): array {
                $callbackCalls++;
                return ['prefix', $database->getDocument('wafRules', 'rule-a')];
            },
        );

        $this->assertSame('rule-a', $first[1]->getId());
        $this->assertSame('rule-a', $second[1]->getId());
        $this->assertSame(2, $callbackCalls);
        $this->assertSame([], $cache->list('key'));
    }

    public function testWithCacheCachesSingleDocument(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
        ]));

        $callbackCalls = 0;
        $collection = $database->getCollection('wafRules');
        $key = $database->getQueryCacheKey($collection->getId(), '_39');
        $hash = $database->getQueryCacheField($collection, field: 'document');

        $first = $database->withCache(
            key: $key,
            callback: function () use ($database, &$callbackCalls): Document {
                $callbackCalls++;
                return $database->getDocument('wafRules', 'rule-a');
            },
            hash: $hash,
        );
        $second = $database->withCache(
            key: $key,
            callback: function () use ($database, &$callbackCalls): Document {
                $callbackCalls++;
                return $database->getDocument('wafRules', 'missing');
            },
            hash: $hash,
        );

        $this->assertSame('rule-a', $first->getId());
        $this->assertSame('rule-a', $second->getId());
        $this->assertSame(1, $callbackCalls);
    }

    public function testWithCacheCachesStaticQueryValues(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', permissions: [
            Permission::read(Role::any()),
        ]);

        $callbackCalls = 0;
        $collection = $database->getCollection('wafRules');
        $key = $database->getQueryCacheKey($collection->getId(), '_39');
        $hash = $database->getQueryCacheField($collection, field: 'count');

        $first = $database->withCache(
            key: $key,
            callback: function () use (&$callbackCalls): int {
                $callbackCalls++;
                return 10;
            },
            hash: $hash,
        );
        $second = $database->withCache(
            key: $key,
            callback: function () use (&$callbackCalls): int {
                $callbackCalls++;
                return 20;
            },
            hash: $hash,
        );

        $this->assertSame(10, $first);
        $this->assertSame(10, $second);
        $this->assertSame(1, $callbackCalls);
    }

    public function testQueryCacheUsesCacheUntilPurged(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', [
            new Document([
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
            'projectId' => 'project-a',
        ]));

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $first = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(1, $first);
        $this->assertSame('rule-a', $first[0]->getId());

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-b',
            'projectId' => 'project-a',
        ]));

        $cached = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(1, $cached);
        $this->assertSame('rule-a', $cached[0]->getId());

        $this->assertTrue($database->purgeCachedQueries('wafRules', '_39'));

        $fresh = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(2, $fresh);
        $this->assertSame(['rule-a', 'rule-b'], \array_map(
            static fn (Document $document): string => $document->getId(),
            $fresh,
        ));
    }

    public function testQueryCacheSeparatesEntriesByAuthorizationContext(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', [
            new Document([
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
            'projectId' => 'project-a',
        ]));

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $this->findWithCache($database, 'wafRules', $queries, '_39');

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-b',
            'projectId' => 'project-a',
        ]));

        $cached = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(1, $cached);

        $database->getAuthorization()->addRole(Role::user('user-1')->toString());

        $roleSeparated = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(2, $roleSeparated);
    }

    public function testQueryCacheRecastsCacheHits(): void
    {
        $cache = new JsonHashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('metrics', [
            new Document([
                '$id' => 'value',
                'type' => Database::VAR_FLOAT,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('metrics', new Document([
            '$id' => 'metric-a',
            'value' => 1.0,
        ]));

        $queries = [
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $fresh = $this->findWithCache($database, 'metrics', $queries, '_39');
        $cached = $this->findWithCache($database, 'metrics', $queries, '_39');

        $this->assertSame(1.0, $fresh[0]->getAttribute('value'));
        $this->assertSame(1.0, $cached[0]->getAttribute('value'));
    }

    public function testQueryCacheDoesNotDoubleDecodeCustomFilters(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache, [
            'wrapped' => [
                'encode' => static fn (mixed $value): string => 'encoded:' . $value,
                'decode' => static fn (mixed $value): string => \str_starts_with((string) $value, 'encoded:')
                    ? \substr((string) $value, 8)
                    : 'double:' . $value,
            ],
        ]);
        $database->createCollection('secrets', [
            new Document([
                '$id' => 'secret',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['wrapped'],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('secrets', new Document([
            '$id' => 'secret-a',
            'secret' => 'value',
        ]));

        $queries = [
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $fresh = $this->findWithCache($database, 'secrets', $queries, '_39');
        $cached = $this->findWithCache($database, 'secrets', $queries, '_39');

        $this->assertSame('value', $fresh[0]->getAttribute('secret'));
        $this->assertSame('value', $cached[0]->getAttribute('secret'));
    }

    public function testQueryCacheBypassesCacheForRandomOrder(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', [
            new Document([
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
            'projectId' => 'project-a',
        ]));

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderRandom(),
            Query::limit(25),
        ];

        $first = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(1, $first);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-b',
            'projectId' => 'project-a',
        ]));

        $second = $this->findWithCache($database, 'wafRules', $queries, '_39');
        $this->assertCount(2, $second);
    }

    public function testQueryCacheReliesOnPurgeForDocumentSecurityCollections(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->getAuthorization()->skip(function () use ($database): void {
            $database->createCollection('secureRules', [
                new Document([
                    '$id' => 'projectId',
                    'type' => Database::VAR_STRING,
                    'size' => 255,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
            ], permissions: [
                Permission::create(Role::any()),
            ], documentSecurity: true);

            $database->createDocument('secureRules', new Document([
                '$id' => 'rule-a',
                '$permissions' => [
                    Permission::read(Role::user('user-1')),
                    Permission::update(Role::any()),
                ],
                'projectId' => 'project-a',
            ]));
        });

        $database->getAuthorization()->addRole(Role::user('user-1')->toString());

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $first = $this->findWithCache($database, 'secureRules', $queries, '_39');
        $this->assertCount(1, $first);

        $database->getAuthorization()->skip(function () use ($database): void {
            $database->updateDocument('secureRules', 'rule-a', new Document([
                '$permissions' => [
                    Permission::read(Role::user('user-2')),
                    Permission::update(Role::any()),
                ],
            ]));
        });

        $cached = $this->findWithCache($database, 'secureRules', $queries, '_39');

        $this->assertCount(1, $cached);

        $this->assertTrue($database->purgeCachedQueries('secureRules', '_39'));

        $fresh = $this->findWithCache($database, 'secureRules', $queries, '_39');
        $this->assertSame([], $fresh);
    }

    public function testQueryCacheFiltersDocumentSecurityPayloadsOnHit(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->getAuthorization()->skip(function () use ($database): void {
            $database->createCollection('secureRules', permissions: [
                Permission::create(Role::any()),
            ], documentSecurity: true);
        });

        $database->getAuthorization()->addRole(Role::user('user-1')->toString());

        $collection = $database->getCollection('secureRules');
        $key = $database->getQueryCacheKey($collection->getId(), '_39');
        $hash = $database->getQueryCacheField($collection);
        $database->getCache()->save($key, [
            'collection' => $collection->getId(),
            'type' => 'documents',
            'value' => [
                [
                    '$id' => 'rule-a',
                    '$permissions' => [
                        Permission::read(Role::user('user-2')),
                    ],
                ],
            ],
        ], $hash);

        $callbackCalls = 0;
        $documents = $database->withCache(
            key: $key,
            callback: function () use (&$callbackCalls): array {
                $callbackCalls++;
                return [
                    new Document([
                        '$id' => 'fresh',
                    ]),
                ];
            },
            hash: $hash,
        );

        $this->assertSame([], $documents);
        $this->assertSame(0, $callbackCalls);
    }

    public function testQueryCacheRehydratesNestedDocumentPayloads(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('parents', permissions: [
            Permission::read(Role::any()),
        ]);

        $queries = [
            Query::limit(25),
        ];

        $collection = $database->getCollection('parents');
        $cache->save(
            $database->getQueryCacheKey($collection->getId(), '_39'),
            [
                'collection' => $collection->getId(),
                'type' => 'documents',
                'value' => [
                    [
                        '$id' => 'parent-a',
                        'child' => [
                            '$id' => 'child-a',
                            '$collection' => 'children',
                            'name' => 'Child A',
                        ],
                    ],
                ],
            ],
            $database->getQueryCacheField($collection, $queries),
        );

        $parents = $this->findWithCache($database, 'parents', $queries, '_39');

        $this->assertCount(1, $parents);
        $this->assertInstanceOf(Document::class, $parents[0]->getAttribute('child'));
        $this->assertSame('child-a', $parents[0]->getAttribute('child')->getId());
    }

    public function testQueryCacheRefreshesInvalidPayload(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', [
            new Document([
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
            'projectId' => 'project-a',
        ]));

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $collection = $database->getCollection('wafRules');
        $cache->save(
            $database->getQueryCacheKey($collection->getId(), '_39'),
            [
                'collection' => $collection->getId(),
                'type' => 'documents',
                'value' => 'invalid',
            ],
            $database->getQueryCacheField($collection, $queries),
        );

        $rules = $this->findWithCache($database, 'wafRules', $queries, '_39');

        $this->assertCount(1, $rules);
        $this->assertSame('rule-a', $rules[0]->getId());
    }

    public function testQueryCacheRefreshesInvalidPayloadEntry(): void
    {
        $cache = new HashMemoryCache();
        $database = $this->createDatabase($cache);
        $database->createCollection('wafRules', [
            new Document([
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-a',
            'projectId' => 'project-a',
        ]));
        $database->createDocument('wafRules', new Document([
            '$id' => 'rule-b',
            'projectId' => 'project-a',
        ]));

        $queries = [
            Query::equal('projectId', ['project-a']),
            Query::orderAsc('$id'),
            Query::limit(25),
        ];

        $collection = $database->getCollection('wafRules');
        $cache->save(
            $database->getQueryCacheKey($collection->getId(), '_39'),
            [
                'collection' => $collection->getId(),
                'type' => 'documents',
                'value' => [
                    [
                        '$id' => 'rule-a',
                        'projectId' => 'project-a',
                    ],
                    'invalid',
                ],
            ],
            $database->getQueryCacheField($collection, $queries),
        );

        $rules = $this->findWithCache($database, 'wafRules', $queries, '_39');

        $this->assertSame(['rule-a', 'rule-b'], \array_map(
            static fn (Document $document): string => $document->getId(),
            $rules,
        ));
    }
}

class HashMemoryCache implements Adapter
{
    /**
     * @var array<string, array<string, array{time: int, data: array<int|string, mixed>|string}>>
     */
    private array $store = [];

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $hash = $hash === '' ? $key : $hash;
        $saved = $this->store[$key][$hash] ?? null;
        if ($saved === null) {
            return false;
        }

        return ($saved['time'] + $ttl > \time()) ? $saved['data'] : false;
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if ($key === '' || empty($data)) {
            return false;
        }

        $hash = $hash === '' ? $key : $hash;
        $this->store[$key][$hash] = [
            'time' => \time(),
            'data' => $data,
        ];

        return $data;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        $hash = $hash === '' ? $key : $hash;
        if (!isset($this->store[$key][$hash])) {
            return false;
        }

        $this->store[$key][$hash]['time'] = \time();

        return true;
    }

    /**
     * @return array<string>
     */
    public function list(string $key): array
    {
        return \array_keys($this->store[$key] ?? []);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash !== '') {
            unset($this->store[$key][$hash]);
            return true;
        }

        unset($this->store[$key]);

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
        return 'hash-memory';
    }
}

class JsonHashMemoryCache implements Adapter
{
    /**
     * @var array<string, array<string, array{time: int, data: string}>>
     */
    private array $store = [];

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $hash = $hash === '' ? $key : $hash;
        $saved = $this->store[$key][$hash] ?? null;
        if ($saved === null || $saved['time'] + $ttl <= \time()) {
            return false;
        }

        return \json_decode($saved['data'], true);
    }

    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        if ($key === '' || empty($data)) {
            return false;
        }

        $hash = $hash === '' ? $key : $hash;
        $this->store[$key][$hash] = [
            'time' => \time(),
            'data' => \json_encode($data) ?: '',
        ];

        return $data;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        $hash = $hash === '' ? $key : $hash;
        if (!isset($this->store[$key][$hash])) {
            return false;
        }

        $this->store[$key][$hash]['time'] = \time();

        return true;
    }

    /**
     * @return array<string>
     */
    public function list(string $key): array
    {
        return \array_keys($this->store[$key] ?? []);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash !== '') {
            unset($this->store[$key][$hash]);
            return true;
        }

        unset($this->store[$key]);

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
        return 'json-hash-memory';
    }
}
