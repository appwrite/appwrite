<?php

/**
 * Boots app/realtime.php with fake databases and canned connection resources, so the
 * pub/sub ingest can be driven end to end without a platform database.
 *
 * Spawned as its own process by PermissionsChangedTest. Fake user lookups:
 * - `frozen`: blocks forever when TEST_FROZEN_BLOCKS=true, standing in for a read
 *   on a silently dead (half-open) database connection
 * - `broken`: throws, standing in for a failed database read
 * - `revoked`: resolves to a blocked user
 * - `imp...`: resolves to an impersonator
 * - anything else: resolves to an active user
 *
 * Every user holds one session, `session-<userId>`, created an hour ago. Fake project
 * lookups:
 * - `frozen-project`: blocks forever when TEST_FROZEN_BLOCKS=true
 * - any project: has invalidated every session when TEST_SESSIONS_INVALIDATED=true
 */

// Lead a process group, so the test can signal the Swoole master, manager and worker at once.
\posix_setsid();

require_once __DIR__ . '/../../../../app/init.php';

use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Swoole\Coroutine\Channel;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;

final class FakeDatabase
{
    private Authorization $authorization;

    public function __construct(private bool $frozenBlocks)
    {
        $this->authorization = new Authorization();
    }

    public function getAuthorization(): Authorization
    {
        return $this->authorization;
    }

    public function setAuthorization(Authorization $authorization): static
    {
        $this->authorization = $authorization;

        return $this;
    }

    public function setMetadata(string $key, mixed $value): static
    {
        return $this;
    }

    public function getDocument(string $collection, string $id): Document
    {
        if ($collection === 'projects') {
            if ($id === 'frozen-project' && $this->frozenBlocks) {
                (new Channel(1))->pop(); // never pushed: a read on a half-open connection
            }

            return new Document([
                '$id' => $id,
                '$sequence' => 1,
                'auths' => \getenv('TEST_SESSIONS_INVALIDATED') === 'true'
                    ? ['sessionsInvalidatedAt' => DateTime::formatTz(DateTime::now())]
                    : [],
                'apis' => [],
                'platforms' => [],
                'region' => '',
            ]);
        }

        if ($collection === 'users') {
            if ($id === 'frozen' && $this->frozenBlocks) {
                (new Channel(1))->pop(); // never pushed: a read on a half-open connection
            }

            if ($id === 'broken') {
                throw new \RuntimeException('Connection reset by peer');
            }

            return makeUser($id, status: $id !== 'revoked');
        }

        return new Document();
    }

    public function createDocument(string $collection, Document $document): Document
    {
        return $document;
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        return $document;
    }

    public function find(string $collection, array $queries = []): array
    {
        return [];
    }

    public function findOne(string $collection, array $queries = []): Document
    {
        return new Document();
    }

    public function purgeCachedDocument(string $collection, string $id): bool
    {
        return true;
    }
}

final class AllowAllOrigins
{
    public function isValid(mixed $value): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Allows every origin';
    }
}

function makeUser(string $userId, bool $status = true): User
{
    return new User([
        '$id' => $userId,
        '$createdAt' => DateTime::now(),
        '$updatedAt' => DateTime::now(),
        'name' => $userId,
        'email' => $userId . '@example.com',
        'emailVerification' => true,
        'phone' => '',
        'phoneVerification' => false,
        'status' => $status,
        'registration' => DateTime::now(),
        'prefs' => [],
        'sessions' => [new Document([
            '$id' => 'session-' . $userId,
            '$createdAt' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -3600)),
            'expire' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), 3600)),
            'factors' => ['password'],
        ])],
        'impersonator' => \str_starts_with($userId, 'imp'),
        'memberships' => [],
        'labels' => [],
        'mfa' => false,
        'accessedAt' => DateTime::now(),
    ]);
}

$fakeDatabase = new FakeDatabase(\getenv('TEST_FROZEN_BLOCKS') === 'true');

function getConsoleDB(): FakeDatabase
{
    global $fakeDatabase;

    return $fakeDatabase;
}

function getProjectDB(Document $project): FakeDatabase
{
    global $fakeDatabase;

    return $fakeDatabase;
}

function triggerStats(array $event, string $projectId): void
{
}

function checkForProjectUsage(Document $project): void
{
}

$registerConnectionResources = function (Container $container): void {
    $container->set('authorization', fn () => new Authorization(), []);

    $container->set('project', function (Request $request) {
        return new Document([
            '$id' => (string) $request->getParam('project', 'test'),
            '$sequence' => 1,
            'auths' => [],
            'apis' => [],
            'platforms' => [],
            'region' => '',
            'teamId' => '',
        ]);
    }, ['request']);

    $container->set('mode', fn () => APP_MODE_DEFAULT, []);
    $container->set('plan', fn () => [], []);
    $container->set('timelimit', fn () => fn (string $key, int $limit, int $seconds, callable $callback): bool => false, []);
    $container->set('originValidator', fn () => new AllowAllOrigins(), []);

    $container->set('user', function (Request $request): User {
        $userId = (string) $request->getParam('testUser', '');

        return $userId === '' ? new User([]) : makeUser($userId);
    }, ['request']);

    // `testImpersonate=<userId>` makes `testUser` impersonate that user; the impersonator
    // belongs to `testImpersonatorProject`, the connection's project by default.
    $container->set('impersonatorUser', function (Request $request, User $user, Document $project): Document {
        if ($user->isEmpty() || (string) $request->getParam('testImpersonate', '') === '') {
            return new Document();
        }

        return new Document([
            '$id' => $user->getId(),
            'projectId' => (string) $request->getParam('testImpersonatorProject', $project->getId()),
        ]);
    }, ['request', 'user', 'project']);

    $container->set('targetUser', function (Request $request, User $user): User {
        $targetId = (string) $request->getParam('testImpersonate', '');

        return $targetId === '' ? $user : makeUser($targetId);
    }, ['request', 'user']);

    // `testSession=1` opens the connection on the user's session (always on it when impersonating).
    $container->set('session', function (Request $request, User $user): ?Document {
        $withSession = $request->getParam('testSession', '') !== '' || $request->getParam('testImpersonate', '') !== '';
        if ($user->isEmpty() || !$withSession) {
            return null;
        }

        return $user->getAttribute('sessions')[0];
    }, ['request', 'user']);
    $container->set('jwtExpire', fn () => null, []);
};

require __DIR__ . '/../../../../app/realtime.php';
