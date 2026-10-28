<?php

declare(strict_types=1);

namespace Tests\Unit\Presences;

use Appwrite\Presences\State;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Presences\Mock\ConflictingMemory;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

final class StateTest extends TestCase
{
    private const string USER_INTERNAL_ID = '42';

    private Database $database;

    private ConflictingMemory $adapter;

    private int $created = 0;

    /** @var array<array{\Redis, string}> */
    private array $holdKeys = [];

    protected function setUp(): void
    {
        $this->adapter = new ConflictingMemory();

        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new Database($this->adapter, new Cache(new NoCache()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('presences')
            ->setNamespace('presences_' . \uniqid());

        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: State::COLLECTION_ID,
            attributes: [
                Attribute::string(key: 'userInternalId', size: 255, required: true),
                Attribute::string(key: 'userId', size: 255),
                Attribute::integer(key: 'connections', default: 0, signed: false),
                Attribute::string(key: 'status', size: 255),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
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

    protected function tearDown(): void
    {
        foreach ($this->holdKeys as [$redis, $key]) {
            $redis->del($key);
        }
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

    public function testReleasedHoldIsNotOrphaned(): void
    {
        [$cache, $host] = $this->holds();
        (new State($host, 'previous'))->recordHold($cache, 7, 'project', 'user', '1');
        (new State($host, 'previous'))->forgetHold($cache, 7);

        $this->assertSame([], (new State($host, 'current'))->getOrphanedHolds($cache, previousRuns: true));
    }

    public function testPreviousRunHoldsAreOrphanedForWorkerZeroOnly(): void
    {
        [$cache, $host] = $this->holds();
        (new State($host, 'previous'))->recordHold($cache, 7, 'project', 'user', '1');
        $current = new State($host, 'current');

        $this->assertCount(1, $current->getOrphanedHolds($cache, previousRuns: true));
        $this->assertSame([], $current->getOrphanedHolds($cache, previousRuns: false));
    }

    public function testDeadWorkerHoldIsOrphanedForEveryWorker(): void
    {
        [$cache, $host, $redis] = $this->holds();
        $current = new State($host, 'current');
        $current->recordHold($cache, 7, 'project', 'user', '1');
        // The same record, written by a worker process that no longer exists.
        $key = 'presence-holds:' . $host;
        $redis->hSet($key, 'current:999999:7', $redis->hGet($key, 'current:' . \getmypid() . ':7'));
        $redis->hDel($key, 'current:' . \getmypid() . ':7');

        $this->assertSame(['current:999999:7'], \array_keys($current->getOrphanedHolds($cache, previousRuns: false)));
    }

    public function testLiveWorkerHoldIsNotOrphaned(): void
    {
        [$cache, $host] = $this->holds();
        $current = new State($host, 'current');
        $current->recordHold($cache, 7, 'project', 'user', '1');

        $this->assertSame([], $current->getOrphanedHolds($cache, previousRuns: true));
    }

    public function testOrphanedHoldIsClaimedOnce(): void
    {
        [$cache, $host] = $this->holds();
        (new State($host, 'previous'))->recordHold($cache, 7, 'project', 'user', '1');
        $current = new State($host, 'current');
        $field = \array_key_first($current->getOrphanedHolds($cache, previousRuns: true));

        $this->assertSame([true, false], [
            $current->claimOrphanedHold($cache, $field),
            $current->claimOrphanedHold($cache, $field),
        ]);
    }

    public function testRestoredHoldIsOrphanedAgain(): void
    {
        [$cache, $host] = $this->holds();
        (new State($host, 'previous'))->recordHold($cache, 7, 'project', 'user', '1');
        $current = new State($host, 'current');
        $holds = $current->getOrphanedHolds($cache, previousRuns: true);
        $field = \array_key_first($holds);
        $current->claimOrphanedHold($cache, $field);

        $current->restoreOrphanedHold($cache, $field, $holds[$field]);

        $this->assertSame($holds, $current->getOrphanedHolds($cache, previousRuns: true));
    }

    public function testRecordingAHoldAgainRenewsItsExpiry(): void
    {
        [$cache, $host, $redis] = $this->holds();
        $state = new State($host, 'current');
        $state->recordHold($cache, 7, 'project', 'user', '1');
        $redis->expire('presence-holds:' . $host, 60);

        $state->recordHold($cache, 7, 'project', 'user', '1');

        $this->assertGreaterThan(29 * 24 * 60 * 60, $redis->ttl('presence-holds:' . $host));
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

    /**
     * @return array{Cache, string, \Redis} a cache on the real Redis, and a host of its own
     */
    private function holds(): array
    {
        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));

        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $redis->auth(\array_filter([System::getEnv('_APP_REDIS_USER', ''), $password]));
        }

        $host = 'state-test-' . \bin2hex(\random_bytes(8));
        $this->holdKeys[] = [$redis, 'presence-holds:' . $host];

        return [new Cache(new RedisCache($redis)), $host, $redis];
    }
}
