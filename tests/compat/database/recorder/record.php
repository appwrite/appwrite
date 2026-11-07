<?php

/**
 * Ports utopia-php/database's e2e suites to compat cases by recording them.
 *
 *   bin/compat-php tests/compat/database/recorder/record.php <profile> [<file filter>[,<file filter>...]]
 *
 * Runs every test method of an e2e adapter test class (Profile::get():
 * `postgres` tests/e2e/Adapter/PostgresTest.php and `shared`
 * SharedTables/PostgresTest.php against the compat PostgreSQL, `pool`,
 * `mirror`, `redis` and `shared-redis` the Pool, Mirror and Redis suites,
 * `mariadb`, `mysql` MariaDBTest/MySQLTest and `shared-mariadb`,
 * `shared-mysql` their SharedTables twins against the compat MariaDB/MySQL,
 * `sqlite` SQLiteTest.php and `sqlite-shared` SharedTables/SQLiteTest.php on
 * a SQLite file, `memory` MemoryTest.php, `mongo`, `mongo-shared` and
 * `mongo-schemaless` the MongoDB suites) in declaration order (honouring
 * @depends and @dataProvider), with a recording Database, adapter and
 * Authorization. Each test method becomes a self-contained case (see
 * README.md here), written to tests/compat/database/cases/ under the
 * profile's prefix (`Scope-[<Profile>-]*.json`, `MariaDB-Scope-*.json`,
 * `MySQL-Scope-*.json`), and <Adapter>Test.json for the class's own tests;
 * what PHP returned while recording goes to recorder/out/<profile>.json for
 * `fidelity.py`. Expectations are then written by `bin/compat record`.
 */

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Codec;
use Tests\Compat\Database\Core;
use Tests\Compat\Session;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\PDO;

\ini_set('memory_limit', '4G');

$root = \dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
require_once $root . '/tests/compat/php/Fault.php';
require_once $root . '/tests/compat/php/Session.php';
require_once $root . '/tests/compat/php/Codec.php';
require_once $root . '/tests/compat/database/Values.php';
require_once $root . '/tests/compat/database/ops/core.php';
require_once __DIR__ . '/Recorder.php';
require_once __DIR__ . '/Classes.php';
require_once __DIR__ . '/Cases.php';
require_once __DIR__ . '/Profile.php';

// The suite's classes. A file that builds its own Database (`new
// Database(...)`, a peer of the suite's) loads from a copy where that is a
// recorded PeerDatabase, with __DIR__ still the original directory; the
// suite's hooks log their calls (Recorder::log) for `db.log`; a MongoDB
// client the test opens itself reaches the suite's server.
const PATCHED = '/tmp/cxrecorder-e2e/';
const PATCHES = [
    // Support\EventRecorder: every event it is handed.
    'Support/EventRecorder.php' => [
        "    public function handle(Domain \$event): void\n    {\n" => "    public function handle(Domain \$event): void\n    {\n        \\Tests\\Compat\\Database\\Recorder\\Recorder::logHook(\$this);\n",
    ],
    // CollectionTests::testTransformations' transform: each statement it rewrites.
    'Scopes/CollectionTests.php' => [
        "                \$this->query = \$query;\n" => "                \\Tests\\Compat\\Database\\Recorder\\Recorder::log();\n                \$this->query = \$query;\n",
    ],
    // NestedPreparationTests' nested hook: each document it prepares.
    'Scopes/Relationships/NestedPreparationTests.php' => [
        "                (\$this->record)(\$collection->getId(), \$document->getId());\n" => "                \\Tests\\Compat\\Database\\Recorder\\Recorder::log();\n                (\$this->record)(\$collection->getId(), \$document->getId());\n",
    ],
];
\spl_autoload_register(function (string $class) use ($root) {
    if (!str_starts_with($class, 'Tests\\E2E\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, \strlen('Tests\\E2E\\'))) . '.php';
    $file = $root . '/vendor/utopia-php/database/tests/e2e/' . $relative;
    if (!is_file($file)) {
        return;
    }
    $source = (string) file_get_contents($file);
    $patches = PATCHES[substr($relative, \strlen('Adapter/'))] ?? [];
    if (!str_contains($source, 'new Database(') && !str_contains($source, 'new Memory()') && !str_contains($source, "'mongo', 27017") && $patches === []) {
        require_once $file;

        return;
    }
    foreach ($patches as $from => $to) {
        if (!str_contains($source, $from)) {
            throw new \RuntimeException("recorder: the patch of {$relative} no longer applies");
        }
        $source = str_replace($from, $to, $source);
    }
    $source = str_replace(
        ['new Database(', '__DIR__', 'new Memory()', "'mongo', 27017"],
        [
            'new \\Tests\\Compat\\Database\\Recorder\\PeerDatabase(',
            var_export(\dirname($file), true),
            'new \\Tests\\Compat\\Database\\Recorder\\PeerMemory()',
            "'compat-database-mongo', 27017",
        ],
        $source,
    );
    $copy = PATCHED . $relative;
    @mkdir(\dirname($copy), 0777, true);
    file_put_contents($copy, $source);
    require_once $copy;
});

Classes::load();

// PHPUnit's configuration, which some TestCase methods read; the tests run
// outside its runner.
\PHPUnit\TextUI\Configuration\Registry::init(
    (new \PHPUnit\TextUI\CliArguments\Builder())->fromParameters([]),
    \PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create(),
);

$profile = $argv[1] ?? 'postgres';
$filter = $argv[2] ?? null;
if (Profile::get($profile) === null) {
    fwrite(STDERR, "usage: record.php <postgres|shared|pool|mirror|redis|shared-redis|sqlite|sqlite-shared|memory|mariadb|shared-mariadb|mysql|shared-mysql|mongo|mongo-shared|mongo-schemaless> [<filter>]\n");
    exit(2);
}

/**
 * The connection and schema the recording runs in.
 */
final class Target
{
    /** The SQLite file the `sqlite` profiles record on (in the PHP container). */
    public const SQLITE_FILE = '/tmp/cxrecorder.sqlite';

    public static string $profile = 'postgres';

    public static Profile $config;

    public static ?PDO $pdo = null;

    public static ?Database $database = null;

    public static function engine(): string
    {
        return self::$config->engine();
    }

    public static function url(): array
    {
        return parse_url(match (self::engine()) {
            'mariadb' => 'mysql://root:password@compat-database-mariadb:3306',
            'mysql' => 'mysql://root:password@compat-database-mysql:3306',
            default => 'postgres://root:password@compat-database-postgres:5432/root',
        });
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null && self::$config->engine() === 'sqlite') {
            @unlink(self::SQLITE_FILE);
            self::$pdo = new PDO('sqlite:' . self::SQLITE_FILE, null, null, Core::PDO_ATTRIBUTES);
        } elseif (self::$pdo === null && \in_array(self::$config->engine(), ['mariadb', 'mysql'], true)) {
            $url = self::url();
            self::$pdo = new PDO("mysql:host={$url['host']};port={$url['port']};charset=utf8mb4", $url['user'], $url['pass'], Core::PDO_ATTRIBUTES);
        }

        return self::$pdo ??= self::connect();
    }

    /**
     * A new connection to the recording's PostgreSQL.
     */
    public static function connect(): PDO
    {
        $url = parse_url('postgres://root:password@compat-database-postgres:5432/root');

        return new PDO("pgsql:host={$url['host']};port={$url['port']};dbname=root", $url['user'], $url['pass'], Core::PDO_ATTRIBUTES);
    }

    public static ?PDO $mirrorPdo = null;

    /**
     * The connection to the recording's mirror destination (PostgreSQL
     * database `mirror`).
     */
    public static function mirrorPdo(): PDO
    {
        return self::$mirrorPdo ??= new PDO('pgsql:host=compat-database-postgres;port=5432;dbname=mirror', 'root', 'password', Core::PDO_ATTRIBUTES);
    }

    /**
     * The services of the recording's caches and Redis adapter: databases of
     * compat-database-redis the cases do not use.
     */
    public static function session(): Session
    {
        return new Session('compat', [
            'database-postgres' => 'postgres://root:password@compat-database-postgres:5432/root',
            'database-redis' => 'redis://compat-database-redis:6379/6',
            'database-redis-mirror' => 'redis://compat-database-redis:6379/7',
            'database-redis-adapter' => 'redis://compat-database-redis:6379/8',
        ]);
    }

    /**
     * The adapter and cache of the profile's database.
     *
     * @return array{0: \Utopia\Database\Adapter, 1: \Utopia\Cache\Cache}
     */
    private static function backing(): array
    {
        $session = self::session();

        return match (self::$profile) {
            'pool' => [
                new RecordingPool(new \Utopia\Pools\Pool(new \Utopia\Pools\Adapter\Stack(), 'postgres', 10, fn () => new Postgres(self::connect()), timeout: 0.0)),
                Core::cache('redis', $session, fresh: true),
            ],
            'redis', 'shared-redis' => [
                new RecordingRedis(Core::redis($session, 'database-redis-adapter')),
                Core::cache('none'),
            ],
            'sqlite', 'sqlite-shared' => [new RecordingEmulatedSQLite(self::pdo()), Core::cache('memory')],
            'memory' => [new RecordingMemory(), Core::cache('memory')],
            'mariadb', 'shared-mariadb' => [new RecordingMariaDB(self::pdo()), Core::cache('memory')],
            'mysql', 'shared-mysql' => [new RecordingMySQL(self::pdo()), Core::cache('memory')],
            default => [new RecordingPostgres(self::pdo()), Core::cache('memory')],
        };
    }

    /**
     * Whether the profile records on MongoDB.
     */
    public static function mongo(): bool
    {
        return str_starts_with(self::$profile, 'mongo');
    }

    /**
     * A recording Database on the compat MongoDB (profiles mongo*): the
     * client's database is the recorder's schema, dropped first with the
     * tests' other databases (`<schema>_<name>`).
     */
    private static function mongoDatabase(): Database
    {
        $client = new \Utopia\Mongo\Client(Recorder::$schema, 'compat-database-mongo', 27017, 'root', 'password', false);
        $client->connect();
        foreach ($client->listDatabaseNames()->databases as $existing) {
            if ($existing->name === Recorder::$schema || str_starts_with($existing->name, Recorder::$schema . '_')) {
                $client->dropDatabase([], $existing->name);
            }
        }

        return new RecordingDatabase(new RecordingMongo($client), Core::cache('memory'));
    }

    /**
     * A recording Database built like `db.open` builds one for the profile:
     * its hooks (Core::DEFAULT_HOOKS), authorization, database, tenancy and
     * namespace, created.
     */
    public static function database(\Utopia\Database\Validator\Authorization $authorization): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }
        $was = Recorder::$on;
        Recorder::$on = false;
        Core::filters();
        if (self::mongo()) {
            $database = self::mongoDatabase();
        } else {
            $pdos = match (true) {
                self::$config->engine() !== 'postgres' => [],
                self::$profile === 'mirror' => [self::pdo(), self::mirrorPdo()],
                default => [self::pdo()],
            };
            foreach ($pdos as $pdo) {
                $pdo->exec('DROP SCHEMA IF EXISTS "' . Recorder::$schema . '" CASCADE');
                // And the tests' other databases, which the recorder isolates as `<schema>_<name>`.
                $others = $pdo->prepare('SELECT nspname FROM pg_namespace WHERE starts_with(nspname, ?)');
                $others->execute([Recorder::$schema . '_']);
                foreach ($others->fetchAll(\PDO::FETCH_COLUMN) as $other) {
                    $pdo->exec('DROP SCHEMA IF EXISTS "' . $other . '" CASCADE');
                }
            }
            if (\in_array(self::$config->engine(), ['mariadb', 'mysql'], true)) {
                $pdo = self::pdo();
                $pdo->exec('DROP DATABASE IF EXISTS `' . Recorder::$schema . '`');
                // And the tests' other databases, which the recorder isolates as `<schema>_<name>`.
                $others = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE LEFT(SCHEMA_NAME, ?) = ?');
                $others->execute([\strlen(Recorder::$schema) + 1, Recorder::$schema . '_']);
                foreach ($others->fetchAll(\PDO::FETCH_COLUMN) as $other) {
                    $pdo->exec('DROP DATABASE IF EXISTS `' . $other . '`');
                }
            }
            if (\in_array(self::$profile, ['redis', 'shared-redis'], true)) {
                // The recording's keys of an earlier run.
                $redis = Core::redis(self::session(), 'database-redis-adapter');
                $iterator = null;
                while (($keys = $redis->scan($iterator, '*' . Recorder::$schema . '*', 500)) !== false) {
                    if ($keys !== []) {
                        $redis->del($keys);
                    }
                    if ($iterator === 0) {
                        break;
                    }
                }
            }
            if (self::$profile === 'mirror') {
                $session = self::session();
                $source = new RecordingDatabase(new Postgres(self::pdo()), Core::cache('redis', $session, fresh: true));
                $destination = new RecordingDatabase(new Postgres(self::mirrorPdo()), Core::cache('redis-mirror', $session, fresh: true));
                Recorder::$parts = ['src' => $source, 'dst' => $destination];
                $database = new RecordingMirror($source, $destination);
            } else {
                [$adapter, $cache] = self::backing();
                $database = new RecordingDatabase($adapter, $cache);
            }
        }
        $database->setAuthorization($authorization);
        if (self::$profile === 'mongo-schemaless') {
            $database->setSchemaless(true);
        }
        $database->addHook(new Relationships())->addHook(new Permissions());
        $database->setDatabase(Recorder::$schema);
        if (self::$config->shared()) {
            $database->setSharedTables(true)->setTenant(999);
            if (!self::mongo()) {
                $database->setNamespace('');
            } else {
                $database->setNamespace('compat');
            }
        } elseif (self::$profile === 'redis') {
            $database->setNamespace(Recorder::$schema);
        } else {
            $database->setNamespace('compat');
        }
        $database->create();
        self::$database = $database;
        Recorder::$main = $database;
        self::$fresh = self::settings();
        Recorder::$on = $was;

        return $database;
    }

    /** @var array<string, mixed>|null settings of the database when it was fresh */
    public static ?array $fresh = null;

    /**
     * A property of an object, from its class or a parent's.
     */
    private static function property(object $object, string $name): mixed
    {
        for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name) && $class->getProperty($name)->getDeclaringClass()->getName() === $class->getName()) {
                $p = $class->getProperty($name);

                return $p->isInitialized($object) ? $p->getValue($object) : null;
            }
        }

        return null;
    }

    /**
     * The settings of the database, its adapter and its authorization that a
     * test can leave behind, read through their getters (and the few
     * properties without one).
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $db = self::$database;
        if ($db === null) {
            return [];
        }
        $was = Recorder::$on;
        Recorder::$on = false;
        Recorder::$depth++;
        try {
            $adapter = $db->getAdapter();
            $authorization = $db->getAuthorization();
            $hooks = [];
            foreach (['lifecycleHooks', 'decorators', 'nestedHooks', 'queryHooks'] as $kind) {
                foreach ((array) self::property($db, $kind) as $key => $hook) {
                    // The suite's EventRecorders only record: one a test leaves
                    // registered is not carried into later cases.
                    if ($hook::class === 'Tests\\E2E\\Adapter\\Support\\EventRecorder') {
                        continue;
                    }
                    $hooks[$kind][] = [\is_string($key) ? $key : null, Recorder::hookSpec($hook)];
                }
            }
            $relationships = self::property($db, 'relationshipHook');
            $out = [
                'authorization.roles' => $authorization->getRoles(),
                'authorization.status' => $authorization->getStatus(),
                'adapter.database' => $db->getDatabase(),
                'adapter.namespace' => $db->getNamespace(),
                'adapter.tenant' => $db->getTenant(),
                'adapter.sharedTables' => $db->hasSharedTables(),
                'adapter.tenantPerDocument' => $db->isTenantPerDocument(),
                'adapter.metadata' => $db->getMetadata(),
                'adapter.timeouts' => $adapter instanceof \Utopia\Database\Adapter\Feature\Timeouts ? (array) self::property($adapter, 'timeouts') : [],
                'adapter.locks' => self::property($adapter, 'locks'),
                'adapter.writeHooks' => array_map(fn ($h) => Recorder::hookSpec($h), $adapter->getWriteHooks()),
                'adapter.transforms' => array_keys((array) self::property($adapter, 'transforms')),
                'adapter.schemaless' => $adapter instanceof \Utopia\Database\Adapter\Feature\Schemaless ? $adapter->isSchemaless() : null,
                'db.cacheName' => $db->getCacheName(),
                'db.preserveDates' => $db->isPreservingDates(),
                'db.preserveSequence' => $db->isPreservingSequence(),
                'db.checkGrants' => $db->isCheckingGrants(),
                'db.migrating' => $db->isMigrating(),
                'db.maxQueryValues' => $db->getMaxQueryValues(),
                'db.dropUnknownAttributes' => $db->isDroppingUnknownAttributes(),
                'db.filtering' => $db->isFiltering(),
                'db.validation' => $db->isValidating(),
                'db.globalCollections' => $db->getGlobalCollections(),
                'db.documentTypes' => (array) self::property($db, 'documentTypes'),
                'db.profiling' => $db->isProfiling(),
                'db.queryCache' => $db->getQueryCache() === null ? null : 'set',
                'db.cacheWriterTimeout' => self::property($db, 'cacheWriterTimeout'),
                'db.hooks' => $hooks,
                'db.relationships' => $relationships === null ? null : [Recorder::hookSpec($relationships), $relationships->isEnabled()],
                'db.codecs' => array_keys($db->getFilters()->signatures()),
            ];
        } finally {
            Recorder::$depth--;
            Recorder::$on = $was;
        }

        return $out;
    }

    /**
     * The steps that bring a fresh database to `$settings` (what the tests
     * left behind before the next one starts).
     *
     * @param array<string, mixed> $settings
     * @param list<string> $problems
     * @return list<array{op: string, args: array<string, mixed>|\stdClass}>
     */
    public static function restore(array $settings, array &$problems): array
    {
        $steps = [];
        $step = function (string $op, array $args = []) use (&$steps) {
            $steps[] = ['op' => $op, 'args' => $args === [] ? new \stdClass() : $args];
        };
        foreach ($settings as $key => $value) {
            if ((self::$fresh[$key] ?? null) === $value) {
                continue;
            }
            match ($key) {
                'authorization.roles' => (function () use ($step, $value) {
                    $step('db.authorization.clean_roles');
                    foreach ($value as $role) {
                        $step('db.authorization.add_role', ['role' => (string) $role]);
                    }
                })(),
                'authorization.status' => $step('db.authorization.set_status', ['status' => $value]),
                'adapter.database' => $step('database.set_database', ['name' => str_replace(Recorder::$schema, '${ns}', (string) $value)]),
                'adapter.namespace' => $step('database.set_namespace', ['namespace' => str_replace(Recorder::$schema, '${ns}', (string) $value)]),
                'adapter.tenant' => $step('database.set_tenant', ['tenant' => $value]),
                'adapter.sharedTables' => $step('database.set_shared_tables', ['sharedTables' => $value]),
                'adapter.tenantPerDocument' => $step('database.set_tenant_per_document', ['enabled' => $value]),
                'adapter.schemaless' => $step('database.set_schemaless', ['schemaless' => $value]),
                'adapter.timeouts' => (function () use ($step, $value) {
                    $step('database.clear_timeout');
                    foreach ($value as $event => $milliseconds) {
                        $step('database.set_timeout', $event === '*' ? ['milliseconds' => $milliseconds] : ['milliseconds' => $milliseconds, 'event' => ['$enum' => 'Event::' . \Utopia\Database\Event::from((string) $event)->name]]);
                    }
                })(),
                'adapter.metadata' => (function () use ($step, $value) {
                    $step('database.reset_metadata');
                    foreach ($value as $k => $v) {
                        $step('database.set_metadata', ['key' => (string) $k, 'value' => $v]);
                    }
                })(),
                'adapter.locks' => $step('database.set_locks', ['locks' => (bool) $value]),
                // The adapter's own Tenancy hook (shared tables) comes and goes with them.
                'adapter.writeHooks' => (function () use ($value, &$problems) {
                    $fresh = array_values(array_filter(self::$fresh['adapter.writeHooks'] ?? [], fn ($h) => ($h['$hook'] ?? '') !== 'Tenancy'));
                    $now = array_values(array_filter($value, fn ($h) => ($h['$hook'] ?? '') !== 'Tenancy'));
                    if ($fresh !== $now) {
                        $problems[] = 'cannot restore write hooks ' . json_encode($value);
                    }
                })(),
                'adapter.transforms' => $value === [] ? null : $problems[] = 'cannot restore transforms ' . json_encode($value),
                'db.cacheName' => $step('database.set_cache_name', ['name' => $value]),
                'db.preserveDates' => $step('database.set_preserve_dates', ['preserve' => $value]),
                'db.preserveSequence' => $step('database.set_preserve_sequence', ['preserve' => $value]),
                'db.checkGrants' => $step('database.set_check_grants', ['check' => $value]),
                'db.migrating' => $step('database.set_migrating', ['migrating' => $value]),
                'db.maxQueryValues' => $step('database.set_max_query_values', ['max' => $value]),
                'db.dropUnknownAttributes' => $step('database.set_drop_unknown_attributes', ['drop' => $value]),
                'db.filtering' => $step('database.set_filtering', ['filtering' => $value]),
                'db.validation' => $step('database.set_validation', ['validation' => $value]),
                'db.profiling' => $step('database.set_profiling', ['profiling' => $value]),
                'db.cacheWriterTimeout' => $step('database.set_cache_writer_timeout', ['seconds' => $value]),
                'db.globalCollections' => (function () use ($step, $value) {
                    $step('database.reset_global_collections');
                    if ($value !== []) {
                        $step('database.set_global_collections', ['collections' => array_keys($value)]);
                    }
                })(),
                'db.documentTypes' => (function () use ($step, $value) {
                    $step('database.clear_document_types');
                    foreach ($value as $collection => $class) {
                        $step('database.set_document_type', ['collection' => (string) $collection, 'className' => $class]);
                    }
                })(),
                'db.hooks' => (function () use ($step, $value, &$problems) {
                    // Hooks a test left registered: the suite's EventRecorders.
                    $fresh = self::$fresh['db.hooks'] ?? [];
                    foreach ($value as $kind => $hooks) {
                        foreach ($hooks as $i => [$name, $spec]) {
                            if (($fresh[$kind][$i] ?? null) === [$name, $spec]) {
                                continue;
                            }
                            if (!\is_array($spec)) {
                                $problems[] = 'cannot restore the hook ' . json_encode($spec);
                                continue;
                            }
                            $step('database.add_hook', ['hook' => $spec]);
                        }
                    }
                })(),
                'db.relationships' => match (true) {
                    $value === null => $step('database.remove_hook', ['hook' => 'Utopia\\Database\\Hook\\Relationships']),
                    $value[1] === false => $problems[] = 'cannot restore a disabled relationships hook',
                    default => $step('database.add_hook', ['hook' => $value[0]]),
                },
                default => $problems[] = "cannot restore setting {$key}: " . json_encode($value),
            };
        }

        return $steps;
    }

    /**
     * `db.open`'s arguments for the profile.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function open(): array|\stdClass
    {
        return self::$config->open;
    }

    /**
     * The suite's deleteColumn(): drops a column behind the library's back,
     * recorded as `db.sql`.
     */
    public static function deleteColumn(Database $database, string $collection, string $column): void
    {
        $table = $database->getNamespace() . '_' . $collection;
        $sql = match (self::$config->engine()) {
            'sqlite' => "ALTER TABLE `{$table}` DROP COLUMN `{$column}`",
            'mariadb', 'mysql' => 'ALTER TABLE `' . Recorder::$schema . '`.`' . $table . '` DROP COLUMN `' . $column . '`',
            default => 'ALTER TABLE "' . Recorder::$schema . '"."' . $table . '" DROP COLUMN "' . $column . '"',
        };
        Recorder::sql(self::pdo(), $sql, [$collection]);
        if ($database instanceof \Utopia\Database\Mirror) {
            Recorder::sql(self::mirrorPdo(), $sql, [$collection], 'dst');
        }
    }

    /**
     * The suite's deleteIndex(): drops an index behind the library's back,
     * recorded as `db.sql`.
     */
    public static function deleteIndex(Database $database, string $collection, string $index): void
    {
        $name = $database->getNamespace() . '_' . $database->getTenant() . '_' . $collection . '_' . $index;
        $sql = match (self::$config->engine()) {
            'sqlite' => "DROP INDEX `{$name}`",
            'mariadb', 'mysql' => 'DROP INDEX `' . $index . '` ON `' . Recorder::$schema . '`.`' . $database->getNamespace() . '_' . $collection . '`',
            default => 'DROP INDEX "' . Recorder::$schema . '"."' . $name . '"',
        };
        Recorder::sql(self::pdo(), $sql, [$collection]);
        if ($database instanceof \Utopia\Database\Mirror) {
            Recorder::sql(self::mirrorPdo(), $sql, [$collection], 'dst');
        }
    }
}

Target::$profile = $profile;
Target::$config = Profile::get($profile);
Recorder::$schema = Target::$config->schema;

$parent = Target::$config->parent;
$recording = 'Recording' . str_replace('-', '', ucwords($profile, '-')) . 'Test';
// The suite's getDatabase(), with its own signature.
$signature = static function (string $class, string $method): string {
    $m = new \ReflectionMethod($class, $method);
    $params = [];
    foreach ($m->getParameters() as $p) {
        $params[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
    }
    $type = (string) $m->getReturnType();

    return ($m->isPublic() ? 'public' : 'protected') . " function {$method}(" . implode(', ', $params) . ')' . ($type === '' ? '' : ': \\' . ltrim($type, '\\'));
};
$getDatabase = $signature($parent, 'getDatabase');
// Engines with no out-of-band schema keep the suite's own helpers.
$helpers = \in_array(Target::$config->engine(), ['memory', 'mongo'], true) || \in_array($profile, ['redis', 'shared-redis'], true) ? '' : <<<PHP
    protected function deleteColumn(string \$collection, string \$column): bool
    {
        Target::deleteColumn(\$this->getDatabase(), \$collection, \$column);

        return true;
    }

    protected function deleteIndex(string \$collection, string \$index): bool
    {
        Target::deleteIndex(\$this->getDatabase(), \$collection, \$index);

        return true;
    }
PHP;
eval(<<<PHP
namespace Tests\\Compat\\Database\\Recorder;

class {$recording} extends \\{$parent}
{
    public function setUp(): void
    {
        if (self::\$authorization === null) {
            \$was = Recorder::\$on;
            Recorder::\$on = false;
            self::\$authorization = new RecordingAuthorization();
            Recorder::\$on = \$was;
        }
        parent::setUp();
        // Tests switch back to the suite's database by this name.
        \$this->testDatabase = Recorder::\$schema;
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    {$getDatabase}
    {
        if (property_exists(self::class, 'pdo') && !\\in_array(Target::\$config->engine(), ['memory', 'mongo'], true) && !\\in_array(Target::\$profile, ['redis', 'shared-redis'], true)) {
            self::\$pdo = Target::pdo();
        }
        \$database = Target::database(self::\$authorization);
        if (\$database instanceof \\Utopia\\Database\\Mirror) {
            self::\$source = \$database->getSource();
            self::\$destination = \$database->getDestination();
        }

        return \$database;
    }

    {$helpers}
}
PHP);

$class = __NAMESPACE__ . '\\' . $recording;
$reflection = new \ReflectionClass($class);
$scopes = $root . '/vendor/utopia-php/database/tests/e2e/Adapter/';

/**
 * Tests not run, with the reason (listed as not ported).
 */
const NOT_PORTED = [
    'testCacheReconnect' => 'needs a Redis cache it stops and restarts (docker) to test reconnection; the cases use a Memory cache',
    'testConcurrentIncrementsOnMongoDBAllLand' => 'races writers on clients it opens inside Swoole coroutines; concurrent writers are not a sequence of steps',
];

/** @var array<string, array{method: \ReflectionMethod, file: string, depends: list<string>, provider: ?string}> $declared */
$declared = [];
foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
    $name = $method->getName();
    if (!str_starts_with($name, 'test')) {
        continue;
    }
    $path = (string) $method->getFileName();
    $file = str_starts_with($path, PATCHED) ? substr($path, \strlen(PATCHED . 'Adapter/')) : substr($path, \strlen($scopes));
    $depends = [];
    $provider = null;
    foreach (explode("\n", (string) $method->getDocComment()) as $line) {
        $line = trim($line, " \t*/");
        if (str_starts_with($line, '@depends ')) {
            $depends[] = trim(substr($line, 9));
        } elseif (str_starts_with($line, '@dataProvider ')) {
            $provider = trim(substr($line, 14));
        }
    }
    foreach ($method->getAttributes() as $attribute) {
        $args = $attribute->getArguments();
        match ($attribute->getName()) {
            \PHPUnit\Framework\Attributes\Depends::class => $depends[] = (string) $args[0],
            \PHPUnit\Framework\Attributes\DataProvider::class => $provider = (string) $args[0],
            default => null,
        };
    }
    $declared[$name] = ['method' => $method, 'file' => $file, 'depends' => $depends, 'provider' => $provider];
}

// Declaration order, each test after the tests it depends on (PHPUnit's
// resolveDependencies).
$order = [];
$visiting = [];
$visit = function (string $name) use (&$visit, &$order, &$visiting, $declared): void {
    if (isset($order[$name]) || isset($visiting[$name]) || !isset($declared[$name])) {
        return;
    }
    $visiting[$name] = true;
    foreach ($declared[$name]['depends'] as $dependency) {
        $visit($dependency);
    }
    $order[$name] = true;
};
foreach (array_keys($declared) as $name) {
    $visit($name);
}

/** @var list<array{name: string, file: string, method: string, status: string, message: string}> $tests */
$tests = [];
$dirty = [];
$returns = [];
$outcomes = [];
$notPorted = [];
foreach (array_keys($order) as $name) {
    ['file' => $file, 'depends' => $depends, 'provider' => $provider] = $declared[$name];
    if (isset(NOT_PORTED[$name])) {
        $notPorted["{$file}::{$name}"] = NOT_PORTED[$name];
        $outcomes[$name] = 'not ported';
        fwrite(STDERR, sprintf("%-8s %s::%s %s\n", 'omitted', $file, $name, NOT_PORTED[$name]));
        continue;
    }
    $sets = ['' => []];
    if ($provider !== null) {
        $sets = (new \ReflectionMethod($class, $provider))->isStatic() ? $class::$provider() : (new $class($provider))->{$provider}();
    }
    foreach ($sets as $key => $data) {
        $label = $name . ($provider !== null ? '#' . $key : '');
        $args = [];
        $skip = null;
        foreach ($depends as $dependency) {
            if (($outcomes[$dependency] ?? null) !== 'passed') {
                $skip = "depends on {$dependency} (" . ($outcomes[$dependency] ?? 'not run') . ')';
                break;
            }
            $args[] = $returns[$dependency] ?? null;
        }
        if ($provider !== null) {
            $args = array_merge(array_values((array) $data), $args);
        }
        $index = \count($tests);
        $test = ['name' => $label, 'file' => $file, 'method' => $name, 'status' => 'passed', 'message' => ''];
        $instance = new $class($name);
        Recorder::$on = true;
        Recorder::$test = -1;
        try {
            $instance->setUp();
        } catch (\Throwable $e) {
            fwrite(STDERR, "setUp failed: {$e->getMessage()}\n");
        }
        $settings = Target::settings();
        if (Target::$fresh !== null && $settings !== Target::$fresh) {
            $dirty[$index] = Target::restore($settings, Recorder::$problems);
            $differs = [];
            foreach ($settings as $k => $value) {
                if ((Target::$fresh[$k] ?? null) !== $value) {
                    $differs[] = $k . '=' . json_encode($value) . ' (fresh ' . json_encode(Target::$fresh[$k] ?? null) . ')';
                }
            }
            fwrite(STDERR, "         settings differ before {$label}: " . implode(', ', $differs) . "\n");
        }
        if ($skip !== null) {
            $test['status'] = 'skipped';
            $test['message'] = $skip;
        } else {
            Recorder::$test = $index;
            try {
                $returns[$name] = $instance->{$name}(...$args);
            } catch (\PHPUnit\Framework\SkippedTest $e) {
                $test['status'] = 'skipped';
                $test['message'] = $e->getMessage();
            } catch (\PHPUnit\Framework\IncompleteTest $e) {
                $test['status'] = 'incomplete';
                $test['message'] = $e->getMessage();
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $test['status'] = 'failed';
                $test['message'] = $e->getMessage();
            } catch (\Throwable $e) {
                // An exception the test expected ends it the way PHPUnit's expectException would.
                $test['status'] = 'threw';
                $test['message'] = $e::class . ': ' . $e->getMessage();
            }
            Recorder::formats();
        }
        Recorder::$test = -1;
        try {
            $instance->tearDown();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown failed: {$e->getMessage()}\n");
        }
        Recorder::$on = false;
        $outcomes[$name] = \in_array($test['status'], ['passed', 'threw'], true) ? 'passed' : $test['status'];
        $tests[] = $test;
        fwrite(STDERR, sprintf("%-8s %s::%s %s\n", $test['status'], $file, $label, $test['status'] === 'passed' ? '' : substr($test['message'], 0, 160)));
    }
}

/**
 * What PHP returned while recording, as plain data for out/: documents and
 * exceptions a result kept as objects encoded.
 */
function plain(mixed $value): mixed
{
    return match (true) {
        \is_array($value) => array_map(plain(...), $value),
        $value instanceof \Throwable => ['$error' => ['class' => $value::class, 'message' => $value->getMessage()]],
        $value instanceof \Utopia\Database\Document => \Tests\Compat\Database\Values::encode($value),
        \is_object($value) && !$value instanceof \stdClass => ['$object' => $value::class],
        default => $value,
    };
}

\ini_set('memory_limit', '-1');
$cases = new Cases(Target::$config, Recorder::$stream, $tests, Target::open(), Recorder::$schema, $dirty);
$written = $cases->write($root . '/tests/compat/database/cases', $filter);
foreach (array_merge(Recorder::$problems, $cases->problems) as $problem) {
    fwrite(STDERR, "problem: {$problem}\n");
}
@mkdir(__DIR__ . '/out');
try {
    $originals = Codec::encode(['tests' => $tests, 'not_ported' => $notPorted, 'originals' => plain($cases->originals())]);
} catch (\Throwable $e) {
    // A recorded result that is not plain data: the fidelity data is
    // written without the originals.
    fwrite(STDERR, "out/{$profile}.json: originals not written ({$e->getMessage()})\n");
    $originals = Codec::encode(['tests' => $tests, 'not_ported' => $notPorted, 'originals' => []]);
}
file_put_contents(
    __DIR__ . "/out/{$profile}.json",
    json_encode($originals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"
);
fwrite(STDOUT, json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
