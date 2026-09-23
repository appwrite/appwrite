<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Ahc\Jwt\JWT;
use Appwrite\Database\Factory;
use Appwrite\Mqtt\Databases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Capability;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

final class DatabasesTest extends TestCase
{
    private const string CONSOLE = 'console';

    private const string POOL = 'database_db_main';

    private const string NAMESPACE = 'shared';

    private const string KEY = 'mqtt-databases-test-key';

    private const string JWT = 'appwrite-jwt';

    private const string SESSION = 'appwrite-session';

    private const array VARIABLES = [
        '_APP_DATABASE_SHARED_NAMESPACE',
        '_APP_DATABASE_SHARED_TABLES',
        '_APP_OPENSSL_KEY_V1',
    ];

    /** @var array<string, string|false> */
    private array $variables = [];

    private Factory $factory;

    private Container $container;

    private Document $project;

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->variables[$variable] = \getenv($variable);
        }

        \putenv('_APP_DATABASE_SHARED_NAMESPACE=' . self::NAMESPACE);
        \putenv('_APP_OPENSSL_KEY_V1=' . self::KEY);

        // Migrations swap the subquery filters for no-ops process-wide, and a
        // user's sessions are read through subQuerySessions.
        require __DIR__ . '/../../../app/init/database/filters.php';

        // Reports the host it dialled, as a pooled MariaDB, MySQL, PostgreSQL or
        // MongoDB connection does; plain SQLite keys its cache by no host at all.
        $adapter = new class (new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])) extends SQLite {
            public function supports(Capability $feature): bool
            {
                return $feature === Capability::Hostname || parent::supports($feature);
            }

            public function getHostname(): string
            {
                return 'mariadb';
            }
        };

        $pools = new Group();
        $pools->add(new Pool(new Stack(), self::CONSOLE, 1, static fn (): SQLite => $adapter, 1.0));
        $pools->add(new Pool(new Stack(), self::POOL, 1, static fn (): SQLite => $adapter, 1.0));

        $cache = new Cache(new MemoryCache());

        $authorization = new Authorization();
        $authorization->disable();

        $this->factory = new Factory($pools, $cache, $authorization);

        $this->container = new Container();
        $this->container->set('getConsoleDB', fn () => fn (): Database => (new Databases($pools, $cache))->console());
        $this->container->set('getProjectDB', fn () => fn (Document $project): Database => (new Databases($pools, $cache))->project($project));

        $registerConnectionResources = require __DIR__ . '/../../../app/init/mqtt/connection.php';
        $registerConnectionResources($this->container);
    }

    protected function tearDown(): void
    {
        foreach ($this->variables as $variable => $value) {
            \putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    /**
     * @return \Iterator<string, array{bool}>
     */
    public static function tables(): \Iterator
    {
        yield 'dedicated tables' => [false];
        yield 'shared tables' => [true];
    }

    #[DataProvider('tables')]
    public function testConnectRefusesAUserBlockedAfterItConnected(bool $sharedTables): void
    {
        $this->boot($sharedTables);
        $user = $this->createUser();
        $credential = $this->createJwt($user);

        $this->assertSame($this->identity($user), $this->connect(self::JWT, $credential));

        $this->factory->project($this->project)->updateDocument('users', $user->getId(), new Document(['status' => false]));

        $this->assertSame([], $this->connect(self::JWT, $credential), 'CONNECT must refuse a user the servers blocked since its last CONNECT');
    }

    #[DataProvider('tables')]
    public function testConnectRefusesASessionDeletedAfterItConnected(bool $sharedTables): void
    {
        $this->boot($sharedTables);
        $user = $this->createUser();
        [$sessionId, $credential] = $this->createSession($user);

        $this->assertSame($this->identity($user), $this->connect(self::SESSION, $credential));

        $database = $this->factory->project($this->project);
        $database->deleteDocument('sessions', $sessionId);
        $database->purgeCachedDocument('users', $user->getId());

        $this->assertSame([], $this->connect(self::SESSION, $credential), 'CONNECT must refuse a session the servers deleted since its last CONNECT');
    }

    #[DataProvider('tables')]
    public function testConnectRefusesAProjectDeletedAfterItConnected(bool $sharedTables): void
    {
        $this->boot($sharedTables);
        $user = $this->createUser();
        $credential = $this->createJwt($user);

        $this->assertSame($this->identity($user), $this->connect(self::JWT, $credential));

        $this->factory->platform()->deleteDocument('projects', $this->project->getId());

        $this->assertSame([], $this->connect(self::JWT, $credential), 'CONNECT must refuse a project the servers deleted since its last CONNECT');
    }

    #[DataProvider('tables')]
    public function testSubscribeRefusesAUserBlockedAfterItConnected(bool $sharedTables): void
    {
        $this->boot($sharedTables);
        $user = $this->createUser();
        $identity = $this->connect(self::JWT, $this->createJwt($user));
        $authorizer = $this->container->get('authorizer');

        $this->assertTrue($authorizer($identity, 'topic'));

        $this->factory->project($this->project)->updateDocument('users', $user->getId(), new Document(['status' => false]));

        $this->assertFalse($authorizer($identity, 'topic'), 'SUBSCRIBE must refuse a user the servers blocked since it connected');
    }

    private function boot(bool $sharedTables): void
    {
        \putenv('_APP_DATABASE_SHARED_TABLES=' . ($sharedTables ? self::POOL : ''));

        $platform = $this->factory->platform();
        $platform->create();
        $platform->createCollection(new Collection(
            id: 'projects',
            attributes: [Attribute::string(key: 'database', size: 256)],
        ));

        $this->project = $platform->createDocument('projects', new Document([
            '$id' => ID::custom('project-1'),
            '$permissions' => [],
            'database' => 'mysql://' . self::POOL . ($sharedTables ? '?namespace=' . self::NAMESPACE : ''),
        ]));

        $database = $sharedTables ? $this->factory->setup(self::POOL) : $this->factory->project($this->project);
        $database->create();
        $database->createCollection(new Collection(
            id: 'users',
            attributes: [
                Attribute::boolean(key: 'status'),
                Attribute::string(key: 'sessions', size: 16384, filters: ['subQuerySessions']),
            ],
        ));
        $database->createCollection(new Collection(
            id: 'sessions',
            attributes: [
                Attribute::string(key: 'userInternalId', required: true),
                Attribute::string(key: 'provider', size: 128),
                Attribute::string(key: 'secret', size: 512),
                Attribute::datetime(key: 'expire', filters: ['datetime']),
            ],
        ));
    }

    /**
     * @return array<string, string>
     */
    private function connect(string $method, string $credential): array
    {
        $authenticator = $this->container->get('authenticator');

        return $authenticator($this->project->getId(), $method, $credential);
    }

    /**
     * @return array<string, string>
     */
    private function identity(Document $user): array
    {
        return [
            'projectId' => $this->project->getId(),
            'userId' => $user->getId(),
        ];
    }

    private function createUser(): Document
    {
        $userId = ID::unique();

        return $this->factory->project($this->project)->createDocument('users', new Document([
            '$id' => $userId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
            'status' => true,
        ]));
    }

    private function createJwt(Document $user): string
    {
        return (new JWT(self::KEY, 'HS256', 3600, 0))->encode([
            'userId' => $user->getId(),
            'sessionId' => '',
        ]);
    }

    /**
     * @return array{0: string, 1: string} the session's ID and the credential its client connects with
     */
    private function createSession(Document $user): array
    {
        $proof = (new Token())->setHash(new Sha());
        $secret = $proof->generate();

        $database = $this->factory->project($this->project);
        $session = $database->createDocument('sessions', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'userInternalId' => $user->getSequence(),
            'provider' => SESSION_PROVIDER_SERVER,
            'secret' => $proof->hash($secret),
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]));
        $database->purgeCachedDocument('users', $user->getId());

        $credential = (new Store())
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $secret)
            ->encode();

        return [$session->getId(), $credential];
    }
}
