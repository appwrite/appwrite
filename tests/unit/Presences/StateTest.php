<?php

declare(strict_types=1);

namespace Tests\Unit\Presences;

use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Presences\State;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Presences\Mock\ConflictingMemory;
use Tests\Unit\Presences\Mock\FailingDeleteMemory;
use Tests\Unit\Presences\Mock\FailingRealtime;
use Tests\Unit\Presences\Mock\RecordingRealtime;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Queue;

final class StateTest extends TestCase
{
    private const string USER_INTERNAL_ID = '42';

    private const string BOOTED_AT = '2026-01-01 00:00:00.000';

    private const string BEFORE_BOOT = '2025-12-31 23:00:00.000';

    private Database $database;

    private ConflictingMemory $adapter;

    private int $created = 0;

    protected function setUp(): void
    {
        $this->adapter = new ConflictingMemory();
        $this->database = $this->presenceDatabase($this->adapter);
    }

    public function testCreatesPresenceAndReportsCreation(): void
    {
        $presence = $this->upsert('first', 'online');

        $this->assertSame('first', $presence->getId());
        $this->assertSame(1, $this->created);
        $this->assertSame(['online'], $this->statuses());
    }

    public function testUpdatesExistingPresenceWithoutReportingCreation(): void
    {
        $this->upsert('first', 'online');

        $presence = $this->upsert('second', 'away');

        $this->assertSame('first', $presence->getId());
        $this->assertSame(1, $this->created);
        $this->assertSame(['away'], $this->statuses());
    }

    public function testRetryAfterConflictStillReportsCreation(): void
    {
        $this->adapter->conflictOnNextCommit();

        $presence = $this->upsert('first', 'online');

        $this->assertSame('first', $presence->getId());
        $this->assertSame(1, $this->created);
        $this->assertSame(['online'], $this->statuses());
    }

    public function testRetryThatUpdatesConcurrentPresenceDoesNotReportCreation(): void
    {
        $this->adapter->conflictOnNextCommit(fn () => $this->database->createDocument(State::COLLECTION_ID, new Document([
            '$id' => 'concurrent',
            'userInternalId' => self::USER_INTERNAL_ID,
            'status' => 'online',
        ])));

        $presence = $this->upsert('first', 'away');

        $this->assertSame('concurrent', $presence->getId());
        $this->assertSame(0, $this->created, 'The final attempt updated an existing presence, so no creation may be reported.');
        $this->assertSame(['away'], $this->statuses());
    }

    public function testFirstSocketCreatesRowWithOneConnection(): void
    {
        $write = $this->hold('user-1', 'online');

        $this->assertTrue($write['created']);
        $this->assertTrue($write['counted']);
        $this->assertSame(1, $write['presence']->getAttribute('connections'));
    }

    public function testSecondSocketIncrementsConnections(): void
    {
        $first = $this->hold('user-1', 'online');
        $second = $this->hold('user-1', 'online');

        $this->assertFalse($second['created']);
        $this->assertTrue($second['counted']);
        $this->assertSame($first['presence']->getId(), $second['presence']->getId());
        $this->assertSame(2, $second['presence']->getAttribute('connections'));
    }

    public function testSameSocketRefreshDoesNotCount(): void
    {
        $first = $this->hold('user-1', 'online');
        $refresh = $this->hold('user-1', 'busy', (string) $first['presence']->getSequence());

        $this->assertFalse($refresh['created']);
        $this->assertFalse($refresh['counted']);
        $this->assertSame(1, $refresh['presence']->getAttribute('connections'));
        $this->assertSame('busy', $refresh['presence']->getAttribute('status'));
    }

    public function testStaleHeldRowRecounts(): void
    {
        $this->hold('user-1', 'online');
        $write = $this->hold('user-1', 'online', '999999');

        $this->assertFalse($write['created']);
        $this->assertTrue($write['counted']);
        $this->assertSame(2, $write['presence']->getAttribute('connections'));
    }

    public function testGoneHeldRowRecreates(): void
    {
        $first = $this->hold('user-1', 'online');
        (new State())->releaseConnectionForUser($this->database, 'user-1', (string) $first['presence']->getSequence());

        $write = $this->hold('user-1', 'online', (string) $first['presence']->getSequence());

        $this->assertTrue($write['created']);
        $this->assertTrue($write['counted']);
        $this->assertSame(1, $write['presence']->getAttribute('connections'));
    }

    public function testReleaseDecrementsThenDeletesAtZero(): void
    {
        $this->hold('user-1', 'online');
        $sequence = (string) $this->hold('user-1', 'online')['presence']->getSequence();

        (new State())->releaseConnectionForUser($this->database, 'user-1', $sequence);
        $afterFirst = $this->database->findOne(State::COLLECTION_ID, [Query::equal('userId', ['user-1'])]);
        $this->assertSame(1, $afterFirst->getAttribute('connections'));

        (new State())->releaseConnectionForUser($this->database, 'user-1', $sequence);
        $afterSecond = $this->database->findOne(State::COLLECTION_ID, [Query::equal('userId', ['user-1'])]);
        $this->assertTrue($afterSecond->isEmpty());
    }

    public function testReleaseIgnoresMismatchedSequence(): void
    {
        $this->hold('user-1', 'online');

        $this->assertNull((new State())->releaseConnectionForUser($this->database, 'user-1', '999999'));

        $live = $this->database->findOne(State::COLLECTION_ID, [Query::equal('userId', ['user-1'])]);
        $this->assertSame(1, $live->getAttribute('connections'));
    }

    public function testDeleteOrphanedWithoutLiveHostsRemovesOnlyThisHostsPresences(): void
    {
        $this->presence('orphan-1', 'realtime', 'host-a');
        $this->presence('orphan-2', 'realtime', 'host-a');
        $this->presence('other-host', 'realtime', 'host-b');
        $this->presence('other-source', 'http', 'host-a');
        $this->presence('after-boot', 'realtime', 'host-a', '2026-01-01 00:00:01.000');

        $publisher = new MockPublisher();
        $queueForRealtime = new RecordingRealtime();

        $deleted = (new State())->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher($publisher, new Queue('usage')),
            $queueForRealtime,
        );

        $this->assertSame(2, $deleted);
        $this->assertSame(['after-boot', 'other-host', 'other-source'], $this->userIds());

        $usage = $publisher->getEvents('usage');
        $this->assertCount(1, $usage);
        $this->assertCount(1, $usage[0]['metrics']);
        $this->assertSame(METRIC_USERS_PRESENCE, $usage[0]['metrics'][0]['key']);
        $this->assertSame(-2, $usage[0]['metrics'][0]['value']);

        $this->assertSame(['presences.[presenceId].delete', 'presences.[presenceId].delete'], \array_column($queueForRealtime->triggered, 'event'));
        $this->assertEqualsCanonicalizing(['orphan-1', 'orphan-2'], \array_column($queueForRealtime->triggered, 'presenceId'));
        $this->assertArrayNotHasKey('hostname', $queueForRealtime->triggered[0]['payload']);
        $this->assertArrayNotHasKey('connections', $queueForRealtime->triggered[0]['payload']);
    }

    public function testDeleteOrphanedReportsPresencesDeletedBeforeAFailure(): void
    {
        // The first batch is deleted, the second fails.
        $this->database = $this->presenceDatabase(new FailingDeleteMemory(failFromCall: 2));
        $this->database->setPreserveDates(true);
        $this->database->createDocuments(State::COLLECTION_ID, \array_map(
            static fn (int $i): Document => new Document([
                '$id' => 'orphan-' . $i,
                '$createdAt' => self::BEFORE_BOOT,
                '$updatedAt' => self::BEFORE_BOOT,
                'userInternalId' => (string) $i,
                'userId' => 'orphan-' . $i,
                'source' => 'realtime',
                'hostname' => 'host-a',
            ]),
            \range(1, Database::BATCH_SIZE + 1),
        ));
        $this->database->setPreserveDates(false);

        $publisher = new MockPublisher();
        $queueForRealtime = new RecordingRealtime();

        try {
            (new State())->deleteOrphaned(
                $this->database,
                new Document(['$id' => 'project-1', '$sequence' => '1']),
                'host-a',
                null,
                self::BOOTED_AT,
                new UsagePublisher($publisher, new Queue('usage')),
                $queueForRealtime,
            );
            $this->fail('The failed bulk delete must be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Bulk delete failed', $e->getMessage());
        }

        $this->assertSame(-Database::BATCH_SIZE, $publisher->getEvents('usage')[0]['metrics'][0]['value']);
        $this->assertCount(Database::BATCH_SIZE, $queueForRealtime->triggered);
    }

    public function testDeleteOrphanedKeepsAnnouncingAfterAFailedEvent(): void
    {
        $this->presence('orphan-1', 'realtime', 'host-a');
        $this->presence('orphan-2', 'realtime', 'host-a');

        $publisher = new MockPublisher();
        $queueForRealtime = new FailingRealtime();

        $deleted = (new State())->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher($publisher, new Queue('usage')),
            $queueForRealtime,
        );

        $this->assertSame(2, $deleted);
        $this->assertCount(1, $queueForRealtime->triggered);
        $this->assertSame(-2, $publisher->getEvents('usage')[0]['metrics'][0]['value']);
    }

    public function testDeleteOrphanedWithNothingToDeleteHasNoSideEffects(): void
    {
        $this->presence('other-host', 'realtime', 'host-b');

        $publisher = new MockPublisher();
        $queueForRealtime = new RecordingRealtime();

        $deleted = (new State())->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher($publisher, new Queue('usage')),
            $queueForRealtime,
        );

        $this->assertSame(0, $deleted);
        $this->assertSame(['other-host'], $this->userIds());
        $this->assertNull($publisher->getEvents('usage'));
        $this->assertSame([], $queueForRealtime->triggered);
    }

    public function testDeleteOrphanedPurgesPresenceListCache(): void
    {
        $this->database = $this->presenceDatabase(new ConflictingMemory(), new Cache(new MemoryCache()));
        $this->presence('orphan', 'realtime', 'host-a');
        $state = new State();
        $state->setListCacheField($this->database, ['any'], [], 'documents', ['cached']);

        $state->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
        );

        $this->assertEmpty($state->getListCacheField($this->database, ['any'], [], 'documents', 3600));
    }

    public function testDeleteOrphanedWithNothingToDeleteKeepsPresenceListCache(): void
    {
        $this->database = $this->presenceDatabase(new ConflictingMemory(), new Cache(new MemoryCache()));
        $this->presence('other-host', 'realtime', 'host-b');
        $state = new State();
        $state->setListCacheField($this->database, ['any'], [], 'documents', ['cached']);

        $state->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
        );

        $this->assertSame(['cached'], $state->getListCacheField($this->database, ['any'], [], 'documents', 3600));
    }

    public function testDeleteOrphanedSkipsProjectWithoutPresences(): void
    {
        $this->database->deleteCollection(State::COLLECTION_ID);

        $deleted = (new State())->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            null,
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
        );

        $this->assertSame(0, $deleted);
    }

    public function testDeleteOrphanedInProjectsSkipsFailingProjects(): void
    {
        $this->presence('orphan-1', 'realtime', 'host-a');

        $result = $this->deleteInProjects($this->platform([], 'broken', 'project-1'), \microtime(true) + 30);

        $this->assertSame(['deleted' => 1, 'projects' => 2, 'failed' => 1, 'outcome' => 'done'], $result);
        $this->assertSame([], $this->userIds());
    }

    public function testDeleteOrphanedInProjectsStopsAtDeadline(): void
    {
        $this->presence('orphan-1', 'realtime', 'host-a');

        $result = $this->deleteInProjects($this->platform([], 'project-1'), \microtime(true) - 1);

        $this->assertSame('timeout', $result['outcome']);
        $this->assertSame(['orphan-1'], $this->userIds());
    }

    public function testDeleteOrphanedInProjectsFailsWhenPlatformNeverReady(): void
    {
        $platform = $this->platform();
        $platform->deleteCollection('projects');

        $result = $this->deleteInProjects($platform, \microtime(true) + 0.05);

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(0, $result['deleted']);
    }

    public function testDeleteOrphanedKeepsLivePeersPresences(): void
    {
        $this->presence('own-old', 'realtime', 'host-a');
        $this->presence('dead-peer', 'realtime', 'host-c');
        $this->presence('live-peer', 'realtime', 'host-b');
        $this->presence('no-host', 'realtime', null);
        $this->presence('after-boot', 'realtime', 'host-c', '2026-01-01 00:00:01.000');

        $deleted = (new State())->deleteOrphaned(
            $this->database,
            new Document(['$id' => 'project-1', '$sequence' => '1']),
            'host-a',
            ['host-b'],
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
        );

        $this->assertSame(2, $deleted);
        $this->assertSame(['after-boot', 'live-peer', 'no-host'], $this->userIds());
    }

    public function testGetLiveHostsHonoursWindowAndSkipsThisHost(): void
    {
        $platform = $this->platform([
            'p1@host-a' => '2025-12-31 23:59:50.000', // this host's earlier run
            'p2@host-b' => '2025-12-31 23:59:01.000', // 59s before boot
            'p3@host-c' => '2025-12-31 23:58:59.000', // 61s before boot
            'p4@host-d' => '2026-01-01 00:00:03.000', // after boot
        ]);

        $hosts = (new State())->getLiveHosts($platform, 'host-a', self::BOOTED_AT, 60);

        $this->assertEqualsCanonicalizing(['host-b', 'host-d'], $hosts);
    }

    public function testGetLiveHostsIsNullWhenAHeartbeatNamesNoHost(): void
    {
        $platform = $this->platform([
            'p2@host-b' => '2025-12-31 23:59:30.000',
            'legacyid' => '2025-12-31 23:59:30.000',
        ]);

        $this->assertNull((new State())->getLiveHosts($platform, 'host-a', self::BOOTED_AT, 60));
    }

    public function testDeleteOrphanedInProjectsUsesHeartbeats(): void
    {
        $this->presence('own-old', 'realtime', 'host-a');
        $this->presence('dead-peer', 'realtime', 'host-c');
        $this->presence('live-peer', 'realtime', 'host-b');

        $result = (new State())->deleteOrphanedInProjects(
            $this->platform(['p2@host-b' => '2025-12-31 23:59:30.000', 'p3@host-c' => '2025-12-31 23:50:00.000'], 'project-1'),
            fn (Document $project): Database => $this->database,
            'host-a',
            self::BOOTED_AT,
            \microtime(true) + 30,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
            0,
        );

        $this->assertSame('done', $result['outcome']);
        $this->assertSame(2, $result['deleted']);
        $this->assertSame(['live-peer'], $this->userIds());
    }

    public function testResumeOrphanedInProjectsRetriesFailedProjects(): void
    {
        $this->presence('orphan-1', 'realtime', 'host-c');
        $calls = 0;

        $result = (new State())->resumeOrphanedInProjects(
            $this->platform([], 'project-1'),
            function (Document $project) use (&$calls): Database {
                // Unreachable on the first attempt only.
                return ++$calls === 1 ? throw new \RuntimeException('Project database unreachable') : $this->database;
            },
            'host-a',
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
            attempts: 3,
            retrySeconds: 0,
        );

        $this->assertSame(['deleted' => 1, 'projects' => 1, 'failed' => 0, 'outcome' => 'done', 'attempts' => 2], $result);
        $this->assertSame([], $this->userIds());
    }

    public function testResumeOrphanedInProjectsGivesUpAfterItsAttempts(): void
    {
        $result = (new State())->resumeOrphanedInProjects(
            $this->platform([], 'broken'),
            fn (Document $project): Database => throw new \RuntimeException('Project database unreachable'),
            'host-a',
            self::BOOTED_AT,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
            attempts: 2,
            retrySeconds: 0,
        );

        $this->assertSame(['deleted' => 0, 'projects' => 1, 'failed' => 1, 'outcome' => 'done', 'attempts' => 2], $result);
    }

    public function testSetPermissionsDefaultsForUser(): void
    {
        $userId = 'user123';
        $user = new User(['$id' => $userId]);
        $authorization = new Authorization();
        $authorization->addRole(Role::user($userId)->toString());

        $document = (new State())->setPermissions(
            new Document(),
            null,
            $user,
            $authorization,
        );

        $this->assertSame($this->expectedPermissions($userId), $document->getAttribute('$permissions'));
        $this->assertSame(\md5(\json_encode($this->expectedPermissions($userId))), $document->getAttribute('permissionsHash'));
    }

    public function testSetPermissionsOwnerOverride(): void
    {
        $ownerId = 'owner456';
        $document = (new State())->setPermissions(
            new Document(),
            null,
            new User(),
            new Authorization(),
            ownerOverride: $ownerId,
        );

        $this->assertSame($this->expectedPermissions($ownerId), $document->getAttribute('$permissions'));
        $this->assertSame(\md5(\json_encode($this->expectedPermissions($ownerId))), $document->getAttribute('permissionsHash'));
    }

    /**
     * @return array<int, string>
     */
    private function expectedPermissions(string $userId): array
    {
        $permissions = [
            (new Permission('read', 'user', $userId))->toString(),
            (new Permission('update', 'user', $userId))->toString(),
            (new Permission('delete', 'user', $userId))->toString(),
            (new Permission('write', 'user', $userId))->toString(),
        ];
        sort($permissions, SORT_STRING);

        return $permissions;
    }

    private function upsert(string $presenceId, string $status): Document
    {
        return (new State())->upsertForUser(
            $this->database,
            new Document([
                'userInternalId' => self::USER_INTERNAL_ID,
                'status' => $status,
            ]),
            $presenceId,
            self::USER_INTERNAL_ID,
            function (): void {
                $this->created++;
            },
        );
    }

    /**
     * @return array{presence: Document, created: bool, counted: bool}
     */
    private function hold(string $userId, string $status, ?string $sequence = null): array
    {
        return (new State())->upsertForUserWithConnections(
            $this->database,
            new Document([
                'userInternalId' => self::USER_INTERNAL_ID,
                'userId' => $userId,
                'status' => $status,
            ]),
            $userId,
            $sequence,
        );
    }

    private function presenceDatabase(Memory $adapter, ?Cache $cache = null): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = new Database($adapter, $cache ?? new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('presences')
            ->setNamespace('presences_' . \uniqid());

        $database->create();
        $database->createCollection(Collection::create(
            id: State::COLLECTION_ID,
            attributes: [
                Attribute::string(key: 'userInternalId', size: 255, required: true),
                Attribute::string(key: 'userId', size: 255),
                Attribute::integer(key: 'connections', default: 0, signed: false),
                Attribute::string(key: 'status', size: 255),
                Attribute::string(key: 'source', size: 255),
                Attribute::string(key: 'hostname', size: 255),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));

        return $database;
    }

    private function presence(string $userId, string $source, ?string $hostname, string $updatedAt = self::BEFORE_BOOT): void
    {
        $this->database->setPreserveDates(true);
        try {
            $this->database->createDocument(State::COLLECTION_ID, new Document([
                '$id' => $userId,
                '$createdAt' => $updatedAt,
                '$updatedAt' => $updatedAt,
                'userInternalId' => self::USER_INTERNAL_ID,
                'userId' => $userId,
                'connections' => 1,
                'status' => 'online',
                'source' => $source,
                'hostname' => $hostname,
            ]));
        } finally {
            $this->database->setPreserveDates(false);
        }
    }

    /**
     * @param array<string, string> $heartbeats container => timestamp
     */
    private function platform(array $heartbeats = [], string ...$projectIds): Database
    {
        $platform = new Database(new Memory(), new Cache(new NoCache()));
        $platform
            ->setAuthorization(new Authorization())
            ->setDatabase('platform')
            ->setNamespace('platform_' . \uniqid());
        $platform->create();
        $platform->createCollection(Collection::create(
            id: 'projects',
            attributes: [Attribute::string(key: 'name', size: 255)],
        ));
        $platform->createCollection(Collection::create(
            id: 'realtime',
            attributes: [
                Attribute::string(key: 'container', size: 255, required: true),
                Attribute::datetime(key: 'timestamp'),
                Attribute::string(key: 'value', size: 255, required: true),
            ],
        ));

        $platform->getAuthorization()->skip(function () use ($platform, $heartbeats, $projectIds): void {
            foreach ($projectIds as $projectId) {
                $platform->createDocument('projects', new Document(['$id' => $projectId, 'name' => $projectId]));
            }
            foreach ($heartbeats as $container => $timestamp) {
                $platform->createDocument('realtime', new Document(['container' => $container, 'timestamp' => $timestamp, 'value' => '{}']));
            }
        });

        return $platform;
    }

    /**
     * @return array{deleted: int, projects: int, failed: int, outcome: string}
     */
    private function deleteInProjects(Database $platform, float $deadline, int $retrySeconds = 0): array
    {
        return (new State())->deleteOrphanedInProjects(
            $platform,
            fn (Document $project): Database => $project->getId() === 'broken'
                ? throw new \RuntimeException('Project database unreachable')
                : $this->database,
            'host-a',
            self::BOOTED_AT,
            $deadline,
            new UsagePublisher(new MockPublisher(), new Queue('usage')),
            new RecordingRealtime(),
            $retrySeconds,
        );
    }

    /**
     * @return list<string>
     */
    private function userIds(): array
    {
        $userIds = \array_map(
            static fn (Document $presence): string => $presence->getAttribute('userId'),
            $this->database->find(State::COLLECTION_ID),
        );
        \sort($userIds);

        return $userIds;
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        $presences = $this->database->find(State::COLLECTION_ID, [
            Query::equal('userInternalId', [self::USER_INTERNAL_ID]),
        ]);

        return \array_map(static fn (Document $presence): string => $presence->getAttribute('status'), $presences);
    }
}
