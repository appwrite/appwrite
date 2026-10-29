<?php

/**
 * Boots app/realtime.php with fake databases and canned connection resources, so the
 * pub/sub ingest can be driven end to end without a platform database.
 *
 * Spawned as its own process by PermissionsChangedTest. Fake user lookups:
 * - `frozen`: blocks forever when TEST_FROZEN_BLOCKS=true, standing in for a read
 *   on a silently dead (half-open) database connection
 * - `revoked`: resolves to a blocked user
 * - anything else: resolves to an active user
 */

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
            return new Document([
                '$id' => $id,
                '$sequence' => 1,
                'auths' => [],
                'apis' => [],
                'platforms' => [],
                'region' => '',
            ]);
        }

        if ($collection === 'users') {
            if ($id === 'frozen' && $this->frozenBlocks) {
                (new Channel(1))->pop(); // never pushed: a read on a half-open connection
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
        'sessions' => [],
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

    $container->set('impersonatorUser', fn () => new Document(), []);
    $container->set('targetUser', fn (User $user) => $user, ['user']);
    $container->set('session', fn () => null, []);
    $container->set('jwtExpire', fn () => null, []);
};

require __DIR__ . '/../../../../app/realtime.php';
