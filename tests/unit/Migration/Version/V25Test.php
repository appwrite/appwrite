<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Resource;

final class V25Test extends TestCase
{
    public function testPreservesLegacyResourceWhenInternalIdsCannotBeResolved(): void
    {
        $migration = new class () extends V25 {
            public function __construct()
            {
            }

            public function migrate(Document $document): Document
            {
                return $this->migrateDocument($document);
            }

            #[\Override]
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
            {
                return [];
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceInternalId' => null,
            'resourceType' => Resource::TYPE_DATABASE,
            'parentResourceInternalId' => null,
        ]);

        $result = $migration->migrate($document);

        $this->assertSame('database:collection', $result->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $result->getAttribute('resourceType'));
        $this->assertNull($result->getAttribute('parentResourceId'));
        $this->assertNull($result->getAttribute('parentResourceType'));
        $this->assertNull($result->getAttribute('resourceInternalId'));
        $this->assertNull($result->getAttribute('parentResourceInternalId'));
    }

    public function testRerunResolvesMissingInternalIdsIdempotently(): void
    {
        $migration = new class () extends V25 {
            /**
             * @var array{parentResourceInternalId?: string, resourceInternalId?: string}
             */
            private array $internalIds = [];

            public function __construct()
            {
            }

            public function migrate(Document $document): Document
            {
                return $this->migrateDocument($document);
            }

            /**
             * @param array{parentResourceInternalId?: string, resourceInternalId?: string} $internalIds
             */
            public function setInternalIds(array $internalIds): void
            {
                $this->internalIds = $internalIds;
            }

            /**
             * @return array{parentResourceInternalId?: string, resourceInternalId?: string}
             */
            #[\Override]
            protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
            {
                return $this->internalIds;
            }
        };
        $document = new Document([
            '$id' => 'migration',
            '$collection' => 'migrations',
            'resourceId' => 'database:collection',
            'resourceInternalId' => null,
            'resourceType' => Resource::TYPE_DATABASE,
            'parentResourceInternalId' => null,
        ]);

        $migration->migrate($document);
        $this->assertSame('database:collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('resourceType'));
        $this->assertNull($document->getAttribute('parentResourceId'));

        $migration->setInternalIds([
            'parentResourceInternalId' => '10',
        ]);
        $migration->migrate($document);
        $this->assertSame('database:collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('resourceType'));
        $this->assertNull($document->getAttribute('parentResourceId'));

        $migration->setInternalIds([
            'parentResourceInternalId' => '10',
            'resourceInternalId' => '20',
        ]);
        $migration->migrate($document);
        $afterResolution = $document->getArrayCopy();
        $migration->migrate($document);

        $this->assertSame('10', $document->getAttribute('parentResourceInternalId'));
        $this->assertSame('20', $document->getAttribute('resourceInternalId'));
        $this->assertSame('collection', $document->getAttribute('resourceId'));
        $this->assertSame(Resource::TYPE_COLLECTION, $document->getAttribute('resourceType'));
        $this->assertSame('database', $document->getAttribute('parentResourceId'));
        $this->assertSame(Resource::TYPE_DATABASE, $document->getAttribute('parentResourceType'));
        $this->assertSame($afterResolution, $document->getArrayCopy());
    }

    public function testAddsPushBrokerSchemaToProjectFromPreviousRelease(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A project created on 2.2.0 has no pushLedger and no topic sequence, qos or expiry.
        $added = ['sequence', 'qos', 'expiry'];
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if ($id === 'pushLedger' || ($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $id === 'topics'
                ? \array_filter($collection['attributes'], static fn (Attribute $attribute): bool => !\in_array($attribute->key, $added, true))
                : $collection['attributes'];
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $this->assertInstanceOf(Collection::class, $database->findCollection('pushLedger'));
        $topics = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('topics')->getAttribute('attributes', [])
        );
        foreach ($added as $attribute) {
            $this->assertContains($attribute, $topics);
        }
    }

    public function testAddsSessionGeoAndFileFolderToProjectFromPreviousRelease(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A project created on 1.9.x stores no GeoIP record on sessions and no folder on files.
        $geo = [
            'continentCode',
            'latitude',
            'longitude',
            'timeZone',
            'weatherCode',
            'postalCode',
            'autonomousSystemNumber',
            'autonomousSystemOrganization',
            'connectionType',
            'connectionUsageType',
            'connectionOrganization',
            'isp',
        ];
        $collections = Config::getParam('collections', []);
        foreach ($collections['projects'] as $id => $collection) {
            if (($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $id === 'sessions'
                ? \array_filter($collection['attributes'], static fn (Attribute $attribute): bool => !\in_array($attribute->key, $geo, true))
                : $collection['attributes'];
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $bucket = $authorization->skip(fn () => $database->createDocument('buckets', new Document([
            '$id' => 'bucket',
            'name' => 'Bucket',
            'enabled' => true,
            'maximumFileSize' => 0,
            'allowedFileExtensions' => [],
            'compression' => 'none',
            'encryption' => false,
            'antivirus' => false,
        ])));
        $files = $collections['buckets']['files'];
        $table = 'bucket_' . $bucket->getSequence();
        $database->createCollection(Collection::create(
            id: $table,
            attributes: \array_values(\array_filter($files['attributes'], static fn (Attribute $attribute): bool => $attribute->key !== 'folder')),
            indexes: \array_values(\array_filter($files['indexes'], static fn (Index $index): bool => $index->key !== '_key_folder')),
        ));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $sessions = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('sessions')->getAttribute('attributes', [])
        );
        foreach ($geo as $attribute) {
            $this->assertContains($attribute, $sessions);
        }

        $files = $database->getCollection($table);
        $this->assertContains('folder', \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $files->getAttribute('attributes', [])
        ));
        $this->assertContains('_key_folder', \array_map(
            fn (Document $index) => $index->getId(),
            $files->getAttribute('indexes', [])
        ));
    }

    public function testCreatesVideoCollectionsForProjectFromPreviousRelease(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // These collections did not exist on 2.5.0. Seed its schema before running the upgrade.
        $added = ['videos', 'videosTimelines', 'videosRenditions', 'videosRenditionsSegments', 'videosProfiles', 'videosCaptions', 'videosCaptionsSegments'];
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if (\in_array($id, $added, true) || ($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($collection['attributes']),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $authorization->skip(fn () => $database->createDocument('videos', new Document([
            '$id' => 'uploaded-video',
            'bucketId' => 'bucket',
            'fileId' => 'file',
            'name' => 'Preserved upload',
        ])));
        $migration->execute();

        $video = $authorization->skip(fn () => $database->getDocument('videos', 'uploaded-video'));
        $this->assertSame('Preserved upload', $video->getAttribute('name'));
        $this->assertSame('file', $video->getAttribute('fileId'));
        foreach ($added as $collection) {
            $rows = $authorization->skip(fn () => $database->find($collection));
            $this->assertCount($collection === 'videos' ? 1 : 0, $rows);
        }
    }

    public function testCreatesPresenceLogsWithConnectionsForLegacyProject(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A legacy project that never ran V24 has no presenceLogs collection at all.
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if ($id === 'presenceLogs' || ($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($collection['attributes']),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $this->assertInstanceOf(Collection::class, $database->findCollection('presenceLogs'));
        $attributes = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('presenceLogs')->getAttribute('attributes', [])
        );
        $this->assertContains('connections', $attributes);
    }

    public function testAddsConnectionsToExistingPresenceLogs(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A project created before this change has presenceLogs but no connections column.
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if (($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $id === 'presenceLogs'
                ? \array_filter($collection['attributes'], static fn (Attribute $attribute): bool => $attribute->key !== 'connections')
                : $collection['attributes'];
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($collection['indexes']),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $attributes = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('presenceLogs')->getAttribute('attributes', [])
        );
        $this->assertContains('connections', $attributes);
    }

    public function testBindsExistingPushLedgerToTopicInstances(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // A project created on 2.3.0 keys its pushLedger by topic id only.
        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if (($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $collection['attributes'];
            $indexes = $collection['indexes'];
            if ($id === 'pushLedger') {
                $attributes = \array_filter($attributes, static fn (Attribute $attribute): bool => $attribute->key !== 'topicInternalId');
                $indexes = \array_filter($indexes, static fn (Index $index): bool => !\in_array($index->key, ['_key_topicInternalId_sequence', '_key_message_topicInternalId'], true));
                $indexes[] = Index::key(key: '_key_topic_sequence', attributes: ['topic', 'sequence']);
                $indexes[] = Index::unique(key: '_key_message_topic', attributes: ['messageId', 'topic']);
            }
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($indexes),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));
        $authorization->skip(fn () => $database->createDocument('pushLedger', new Document([
            'topic' => 'news',
            'data' => ['body' => 'before upgrade'],
            'messageId' => 'message',
            'sequence' => 1,
        ])));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $ledger = $database->getCollection('pushLedger');
        $attributes = \array_map(fn (Document $attribute) => $attribute->getId(), $ledger->getAttribute('attributes', []));
        $indexes = \array_map(fn (Document $index) => $index->getId(), $ledger->getAttribute('indexes', []));
        $this->assertContains('topicInternalId', $attributes);
        $this->assertContains('_key_topicInternalId_sequence', $indexes);
        $this->assertNotContains('_key_topic_sequence', $indexes, 'replay no longer reads by topic id, so its index must go');
        $this->assertContains('_key_message_topicInternalId', $indexes);
        $this->assertNotContains('_key_message_topic', $indexes, 'deduplication keys on the topic instance, so the topic id unique index must go');

        $published = $authorization->skip(fn () => $database->createDocument('pushLedger', new Document([
            'topic' => 'news',
            'topicInternalId' => '7',
            'data' => ['body' => 'after upgrade'],
            'messageId' => 'message',
            'sequence' => 2,
        ])));
        $this->assertSame('7', $published->getAttribute('topicInternalId'), 'a row written before the upgrade must not block the same message on a topic instance');
    }

    public function testBackfillsLegacyTypeOnExistingTransactions(): void
    {
        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        $collections = Config::getParam('collections', [])['projects'];
        foreach ($collections as $id => $collection) {
            if (($collection['$collection'] ?? null) !== Database::METADATA) {
                continue;
            }
            $attributes = $id === 'transactions'
                ? \array_filter($collection['attributes'], static fn (Attribute $attribute): bool => $attribute->key !== 'type')
                : $collection['attributes'];
            $indexes = $id === 'transactions'
                ? \array_filter($collection['indexes'], static fn (Index $index): bool => $index->key !== '_key_type')
                : $collection['indexes'];
            $database->createCollection(Collection::create(
                id: $id,
                attributes: \array_values($attributes),
                indexes: \array_values($indexes),
            ));
        }
        $database->createCollection(Collection::create(id: 'audit'));

        $authorization->skip(fn () => $database->createDocument('transactions', new Document([
            '$id' => 'pre-type-transaction',
            'status' => 'pending',
            'operations' => 1,
            'expiresAt' => DateTime::now(),
        ])));

        $migration = new V25();
        $migration->setProject(new Document(['$id' => 'project', '$sequence' => '1']), $database, $database, $authorization);
        $migration->execute();
        $migration->execute();

        $attributes = \array_map(
            fn (Document $attribute) => $attribute->getId(),
            $database->getCollection('transactions')->getAttribute('attributes', [])
        );
        $this->assertContains('type', $attributes);

        $transaction = $authorization->skip(fn () => $database->getDocument('transactions', 'pre-type-transaction'));
        $this->assertSame(DATABASE_TYPE_LEGACY, $transaction->getAttribute('type'));
    }

    public function testRejectsResourcesCreatedAfterMigration(): void
    {
        $migration = new class () extends V25 {
            public function __construct()
            {
            }

            public function isCandidate(Document $resource, Document $migration): bool
            {
                return $this->predatesMigration($resource, $migration);
            }
        };
        $document = new Document([
            '$createdAt' => '2026-01-02T00:00:00.000+00:00',
        ]);

        $this->assertTrue($migration->isCandidate(new Document([
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
        ]), $document));
        $this->assertFalse($migration->isCandidate(new Document([
            '$createdAt' => '2026-01-03T00:00:00.000+00:00',
        ]), $document));
        $this->assertFalse($migration->isCandidate(new Document(), $document));
    }

    public function testDropsDevKeysFromConsoleProjects(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();
        $database->createCollection(Collection::create(
            id: 'projects',
            attributes: [
                Attribute::string(key: 'name', size: 128),
                Attribute::string(key: 'devKeys', size: 16384),
            ],
        ));

        $migration = new class ($database) extends V25 {
            public function __construct(Database $database)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'console', '$sequence' => 'console']);
                $this->collections = ['console' => [
                    'projects' => ['$collection' => Database::METADATA, '$id' => 'projects', 'attributes' => [], 'indexes' => []],
                ]];
            }
        };

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $this->assertSame(['name'], \array_map(
            fn (Attribute $attribute): string => $attribute->key,
            $database->getCollection('projects')->attributes()
        ));
    }

    public function testClearsIdentityEmailsOwnersHaveNotVerified(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        $collections = Config::getParam('collections', [])['projects'];
        // Users keep only the attributes the step reads; the rest carry subquery filters
        $database->createCollection(Collection::create(
            id: 'users',
            attributes: \array_values(\array_filter($collections['users']['attributes'], static fn (Attribute $attribute): bool => \in_array($attribute->key, ['email', 'emailVerification'], true))),
        ));
        $database->createCollection(Collection::create(
            id: 'identities',
            attributes: \array_values($collections['identities']['attributes']),
            indexes: \array_values($collections['identities']['indexes']),
        ));

        $users = [
            'verified' => ['email' => 'owner@example.com', 'emailVerification' => true],
            'unverified' => ['email' => 'pending@example.com', 'emailVerification' => false],
        ];
        foreach ($users as $userId => $user) {
            $database->createDocument('users', new Document(['$id' => $userId, ...$user]));
        }

        // identity => [owner, stored providerEmail, expected providerEmail]
        $identities = [
            'matches' => ['verified', 'owner@example.com', 'owner@example.com'],
            'differs' => ['verified', 'other@example.com', ''],
            'unverifiedOwner' => ['unverified', 'pending@example.com', ''],
            'missingOwner' => ['deleted', 'owner@example.com', ''],
            'empty' => ['verified', '', ''],
        ];
        foreach ($identities as $identityId => [$userId, $providerEmail]) {
            $database->createDocument('identities', new Document([
                '$id' => $identityId,
                'userInternalId' => '1',
                'userId' => $userId,
                'provider' => 'mock',
                'providerUid' => $identityId,
                'providerEmail' => $providerEmail,
            ]));
        }

        $migration = new class ($database, $collections['identities']) extends V25 {
            public function __construct(Database $database, array $identities)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
                $this->collections = ['projects' => ['identities' => $identities]];
            }
        };

        \ob_start();
        try {
            $migration->execute();
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        foreach ($identities as $identityId => [, , $expected]) {
            $this->assertSame($expected, (string) $database->getDocument('identities', $identityId)->getAttribute('providerEmail', ''), $identityId);
        }
    }

    public function testIdentityEmailCleanupFailurePropagates(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        // Without a users collection the owner lookup fails, and the cleanup must not report success
        $collections = Config::getParam('collections', [])['projects'];
        $database->createCollection(Collection::create(
            id: 'identities',
            attributes: \array_values($collections['identities']['attributes']),
            indexes: \array_values($collections['identities']['indexes']),
        ));
        $database->createDocument('identities', new Document([
            '$id' => 'identity',
            'userInternalId' => '1',
            'userId' => 'user',
            'provider' => 'mock',
            'providerUid' => 'identity',
            'providerEmail' => 'owner@example.com',
        ]));

        $migration = new class ($database, $collections['identities']) extends V25 {
            public function __construct(Database $database, array $identities)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
                $this->collections = ['projects' => ['identities' => $identities]];
            }
        };

        $this->expectException(\Throwable::class);

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }
    }

    public function testClearsIdentityEmailsAcrossPages(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('v25_' . \uniqid());
        $database->create();

        $collections = Config::getParam('collections', [])['projects'];
        $database->createCollection(Collection::create(
            id: 'users',
            attributes: \array_values(\array_filter($collections['users']['attributes'], static fn (Attribute $attribute): bool => \in_array($attribute->key, ['email', 'emailVerification'], true))),
        ));
        $database->createCollection(Collection::create(
            id: 'identities',
            attributes: \array_values($collections['identities']['attributes']),
            indexes: \array_values($collections['identities']['indexes']),
        ));
        $database->createDocument('users', new Document(['$id' => 'owner', 'email' => 'owner@example.com', 'emailVerification' => true]));

        // Clearing most rows shrinks the filtered set while the scan advances
        for ($i = 0; $i < 250; $i++) {
            $database->createDocument('identities', new Document([
                '$id' => 'identity' . $i,
                'userInternalId' => '1',
                'userId' => 'owner',
                'provider' => 'mock',
                'providerUid' => 'identity' . $i,
                'providerEmail' => $i % 10 === 0 ? 'owner@example.com' : 'other' . $i . '@example.com',
            ]));
        }

        $migration = new class ($database, $collections['identities']) extends V25 {
            public function __construct(Database $database, array $identities)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
                $this->collections = ['projects' => ['identities' => $identities]];
            }
        };

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $this->assertSame(25, $database->count('identities', [Query::notEqual('providerEmail', '')]));
        $this->assertSame(0, $database->count('identities', [Query::notEqual('providerEmail', ''), Query::notEqual('providerEmail', 'owner@example.com')]));
    }
}
