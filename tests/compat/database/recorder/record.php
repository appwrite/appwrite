<?php

/**
 * Ports utopia-php/database's e2e suites to compat cases by recording them.
 *
 *   bin/compat-php tests/compat/database/recorder/record.php <profile> [<file filter>[,<file filter>...]]
 *
 * Runs every test method of an e2e adapter test class (Target::PROFILES:
 * `postgres` tests/e2e/Adapter/PostgresTest.php and `shared`
 * SharedTables/PostgresTest.php against the compat PostgreSQL, `sqlite`
 * SQLiteTest.php and `sqlite-shared` SharedTables/SQLiteTest.php on a SQLite
 * file, `memory` MemoryTest.php) in declaration order (honouring @depends and
 * @dataProvider), with a recording Database, adapter and Authorization. Each
 * test method becomes a self-contained case (see README.md here), written
 * to tests/compat/database/cases/Scope-[<Adapter>-][SharedTables-]*.json
 * (and <Adapter>Test.json for the class's own tests); what
 * PHP returned while recording goes to recorder/out/<profile>.json for
 * `fidelity.php`. Expectations are then written by `bin/compat record`.
 */

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Codec;
use Tests\Compat\Session;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
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

// The suite's classes. A file that builds its own Database (`new
// Database(...)`, a peer of the suite's) loads from a copy where that is a
// recorded PeerDatabase, with __DIR__ still the original directory.
const PATCHED = '/tmp/cxrecorder-e2e/';
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
    if (!str_contains($source, 'new Database(')) {
        require_once $file;

        return;
    }
    $source = str_replace(
        ['new Database(', '__DIR__', 'new Memory()', '(new \\ReflectionClass($adapter))->getProperty(\'data\')->getValue($adapter)'],
        [
            'new \\Tests\\Compat\\Database\\Recorder\\PeerDatabase(',
            var_export(\dirname($file), true),
            'new \\Tests\\Compat\\Database\\Recorder\\PeerMemory()',
            '\\Tests\\Compat\\Database\\Recorder\\Recorder::memoryData($adapter)',
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
if (!isset(Target::PROFILES[$profile])) {
    fwrite(STDERR, 'usage: record.php <' . implode('|', array_keys(Target::PROFILES)) . "> [<filter>]\n");
    exit(2);
}

/**
 * The connection and schema the recording runs in.
 */
final class Target
{
    /**
     * Per profile: the e2e class recorded (under Tests\E2E\Adapter), its
     * adapter (`engine` of `db.open`), whether its tables are shared (tenant
     * 999, namespace ''), the recorder's schema (database name), the case
     * file prefix and the snapshot kinds of its cases.
     */
    public const PROFILES = [
        'postgres' => ['class' => 'PostgresTest', 'engine' => 'postgres', 'shared' => false, 'schema' => 'cxrecorderp', 'prefix' => 'Scope-', 'state' => ['postgres@database-postgres']],
        'shared' => ['class' => 'SharedTables\PostgresTest', 'engine' => 'postgres', 'shared' => true, 'schema' => 'cxrecorders', 'prefix' => 'Scope-SharedTables-', 'state' => ['postgres@database-postgres']],
        'sqlite' => ['class' => 'SQLiteTest', 'engine' => 'sqlite', 'shared' => false, 'schema' => 'cxrecorderq', 'prefix' => 'Scope-SQLite-', 'state' => ['sqlite']],
        'sqlite-shared' => ['class' => 'SharedTables\SQLiteTest', 'engine' => 'sqlite', 'shared' => true, 'schema' => 'cxrecorderr', 'prefix' => 'Scope-SQLite-SharedTables-', 'state' => ['sqlite']],
        'memory' => ['class' => 'MemoryTest', 'engine' => 'memory', 'shared' => false, 'schema' => 'cxrecorderm', 'prefix' => 'Scope-Memory-', 'state' => []],
    ];

    /** The SQLite file the `sqlite` profiles record on (in the PHP container). */
    public const SQLITE_FILE = '/tmp/cxrecorder.sqlite';

    public static string $profile = 'postgres';

    /**
     * @return array{class: string, engine: string, shared: bool, schema: string, prefix: string, state: list<string>}
     */
    public static function config(): array
    {
        return self::PROFILES[self::$profile];
    }

    public static ?PDO $pdo = null;

    public static ?Database $database = null;

    public static function url(): array
    {
        return parse_url('postgres://root:password@compat-database-postgres:5432/root');
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null && self::config()['engine'] === 'sqlite') {
            @unlink(self::SQLITE_FILE);
            self::$pdo = new PDO('sqlite:' . self::SQLITE_FILE, null, null, SQLite::getPDOAttributes());
        } elseif (self::$pdo === null) {
            $url = self::url();
            self::$pdo = new PDO("pgsql:host={$url['host']};port={$url['port']};dbname=root", $url['user'], $url['pass'], Postgres::getPDOAttributes());
        }

        return self::$pdo;
    }

    /**
     * A recording Database built like `db.open` builds one for the profile.
     */
    public static function database(\Utopia\Database\Validator\Authorization $authorization): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }
        $was = Recorder::$on;
        Recorder::$on = false;
        if (self::config()['engine'] === 'memory') {
            $adapter = new RecordingMemory();
        } elseif (self::config()['engine'] === 'sqlite') {
            $adapter = new RecordingSQLite(self::pdo());
            $adapter->setEmulateMySQL(true);
        } else {
            $pdo = self::pdo();
            $pdo->exec('DROP SCHEMA IF EXISTS "' . Recorder::$schema . '" CASCADE');
            // And the tests' other databases, which the recorder isolates as `<schema>_<name>`.
            $others = $pdo->prepare('SELECT nspname FROM pg_namespace WHERE starts_with(nspname, ?)');
            $others->execute([Recorder::$schema . '_']);
            foreach ($others->fetchAll(\PDO::FETCH_COLUMN) as $other) {
                $pdo->exec('DROP SCHEMA IF EXISTS "' . $other . '" CASCADE');
            }
            $adapter = new RecordingPostgres($pdo);
        }
        $database = new RecordingDatabase($adapter, \Tests\Compat\Database\Core::cache('memory'));
        $database->setAuthorization($authorization)->setDatabase(Recorder::$schema);
        if (self::config()['shared']) {
            $database->setSharedTables(true)->setTenant(999)->setNamespace('');
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

    private const VOLATILE = [
        'adapter', 'cache', 'authorization', 'pdo', 'timestamp', 'relationshipWriteStack', 'relationshipFetchStack',
        'relationshipDeleteStack', 'inBatchRelationshipPopulation', 'relationshipFetchDepth', 'filterSignatures',
        'filterSignaturesEncoded', 'filterSignaturesVersion', 'filterSignaturesSource', 'inTransaction', 'hostname', 'message',
        // SQLite: the FTS5 table lookup cache, and the settings db.open makes.
        'ftsTableCache', 'pcreRegistered', 'emulateMySQL',
        // Memory: the stored data and its indexes, and the filter cache.
        'databases', 'data', 'permissions', 'permissionsByDocument', 'permissionsByPermission', 'uniqueIndexHashes',
        'journals', 'filterCache',
    ];

    /**
     * The settings of the database, its adapter and its authorization: every
     * instance property but the volatile ones, closures as their keys.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $out = [];
        $db = self::$database;
        if ($db === null) {
            return [];
        }
        $was = Recorder::$on;
        Recorder::$on = false;
        foreach (['db' => $db, 'adapter' => $db->getAdapter(), 'authorization' => $db->getAuthorization()] as $label => $object) {
            for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getProperties() as $p) {
                    if ($p->isStatic() || $p->getDeclaringClass()->getName() !== $class->getName() || \in_array($p->getName(), self::VOLATILE, true)) {
                        continue;
                    }
                    $out["{$label}.{$p->getName()}"] = self::plain($p->isInitialized($object) ? $p->getValue($object) : null);
                }
            }
        }
        Recorder::$on = $was;

        return $out;
    }

    /**
     * A property value as plain data: closures and objects as markers, and
     * empty lists dropped from maps (a listener set emptied again).
     */
    private static function plain(mixed $value): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v = self::plain($v);
                if (\is_string($k) && $v === []) {
                    continue;
                }
                $out[$k] = $v;
            }

            return $out;
        }

        return match (true) {
            $value instanceof \Closure => 'closure',
            \is_object($value) => $value::class,
            default => $value,
        };
    }

    /**
     * The steps that bring a fresh database to `$settings` (what the tests
     * left behind before the next one starts).
     *
     * @param array<string, mixed> $settings
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
                    foreach (array_keys($value) as $role) {
                        $step('db.authorization.add_role', ['role' => (string) $role]);
                    }
                })(),
                'authorization.status' => $step('db.authorization.set_status', ['status' => $value]),
                'authorization.statusDefault' => $step('db.authorization.set_default_status', ['status' => $value]),
                'adapter.database' => $step('database.set_database', ['name' => str_replace(Recorder::$schema, '${ns}', (string) $value)]),
                'adapter.namespace' => $step('database.set_namespace', ['namespace' => $value]),
                'adapter.tenant' => $step('database.set_tenant', ['tenant' => $value]),
                'adapter.sharedTables' => $step('database.set_shared_tables', ['sharedTables' => $value]),
                'adapter.tenantPerDocument' => $step('database.set_tenant_per_document', ['enabled' => $value]),
                'adapter.supportForAttributes' => $step('db.adapter', ['method' => 'setSupportForAttributes', 'args' => ['support' => $value]]),
                'adapter.timeout' => $value === 0 ? $step('database.clear_timeout') : $step('database.set_timeout', ['milliseconds' => $value]),
                'adapter.metadata' => (function () use ($step, $value) {
                    $step('database.reset_metadata');
                    foreach ($value as $k => $v) {
                        $step('database.set_metadata', ['key' => (string) $k, 'value' => $v]);
                    }
                })(),
                // setMetadata() registers the `metadata` transformation for good.
                'adapter.transformations' => match (true) {
                    $value !== ['*' => ['metadata' => 'closure']] => $problems[] = 'cannot restore transformations ' . json_encode($value),
                    // With metadata, restoring it registers the transformation too.
                    ($settings['adapter.metadata'] ?? []) !== [] => null,
                    default => (function () use ($step) {
                        $step('database.set_metadata', ['key' => 'compat', 'value' => '']);
                        $step('database.reset_metadata');
                    })(),
                },
                'db.preserveDates' => $step('database.set_preserve_dates', ['preserve' => $value]),
                'db.preserveSequence' => $step('database.set_preserve_sequence', ['preserve' => $value]),
                'db.migrating' => $step('database.set_migrating', ['migrating' => $value]),
                'db.maxQueryValues' => $step('database.set_max_query_values', ['max' => $value]),
                'db.dropUnknownAttributes' => $step('database.set_drop_unknown_attributes', ['drop' => $value]),
                'db.filter' => $step($value ? 'database.enable_filters' : 'database.disable_filters'),
                'db.validate' => $step($value ? 'database.enable_validation' : 'database.disable_validation'),
                'db.cacheName' => $step('database.set_cache_name', ['name' => $value]),
                'db.globalCollections' => (function () use ($step, $value) {
                    $step('database.reset_global_collections');
                    if ($value !== []) {
                        $step('database.set_global_collections', ['collections' => array_keys($value)]);
                    }
                })(),
                'db.documentTypes' => (function () use ($step, $value) {
                    $step('database.clear_all_document_types');
                    foreach ($value as $collection => $class) {
                        $step('database.set_document_type', ['collection' => (string) $collection, 'className' => $class]);
                    }
                })(),
                'db.listeners' => (function () use ($step, $value) {
                    foreach ($value as $event => $names) {
                        foreach (array_keys($names) as $name) {
                            $step('database.on', ['event' => (string) $event, 'name' => (string) $name, 'callback' => true]);
                        }
                    }
                })(),
                default => $problems[] = "cannot restore setting {$key}: " . json_encode($value),
            };
        }

        return $steps;
    }

    /**
     * `db.open`'s arguments for the profile.
     *
     * @return array<string, mixed>
     */
    public static function open(): array|\stdClass
    {
        $open = self::config()['engine'] === 'postgres' ? [] : ['engine' => self::config()['engine']];
        if (self::config()['engine'] === 'sqlite') {
            $open['emulate_mysql'] = true;
        }
        if (self::config()['shared']) {
            $open += ['namespace' => '', 'shared_tables' => true, 'tenant' => 999];
        }

        return $open === [] ? new \stdClass() : $open;
    }

    /**
     * The suite's deleteColumn(): drops a column behind the library's back,
     * recorded as `db.sql`.
     */
    public static function deleteColumn(Database $database, string $collection, string $column): void
    {
        if (self::config()['engine'] === 'memory') {
            // MemoryTest: no out-of-band schema changes.
            return;
        }
        $table = $database->getNamespace() . '_' . $collection;
        $sql = self::config()['engine'] === 'sqlite'
            ? "ALTER TABLE `{$table}` DROP COLUMN `{$column}`"
            : 'ALTER TABLE "' . Recorder::$schema . '"."' . $table . '" DROP COLUMN "' . $column . '"';
        Recorder::sql(self::pdo(), $sql, [$collection]);
    }

    /**
     * The suite's deleteIndex(): drops an index behind the library's back,
     * recorded as `db.sql`.
     */
    public static function deleteIndex(Database $database, string $collection, string $index): void
    {
        if (self::config()['engine'] === 'memory') {
            return;
        }
        $name = $database->getNamespace() . '_' . $database->getTenant() . '_' . $collection . '_' . $index;
        $sql = self::config()['engine'] === 'sqlite'
            ? "DROP INDEX `{$name}`"
            : 'DROP INDEX "' . Recorder::$schema . '"."' . $name . '"';
        Recorder::sql(self::pdo(), $sql, [$collection]);
    }
}

Target::$profile = $profile;
Recorder::$schema = Target::config()['schema'];

$parent = 'Tests\\E2E\\Adapter\\' . Target::config()['class'];
$recording = 'Recording' . str_replace('-', '', ucwords($profile, '-')) . 'Test';
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

    public function getDatabase(): \\Utopia\\Database\\Database
    {
        if (Target::config()['engine'] !== 'memory') {
            self::\$pdo = Target::pdo();
        }

        return Target::database(self::\$authorization);
    }

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
        $sets = (new $class($provider))->{$provider}();
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

\ini_set("memory_limit", "-1");
$cases = new Cases($profile, Recorder::$stream, $tests, Target::open(), Recorder::$schema, $dirty);
$written = $cases->write($root . '/tests/compat/database/cases', $filter);
foreach (array_merge(Recorder::$problems, $cases->problems) as $problem) {
    fwrite(STDERR, "problem: {$problem}\n");
}
@mkdir(__DIR__ . '/out');
try {
    $originals = Codec::encode(['tests' => $tests, 'not_ported' => $notPorted, 'originals' => $cases->originals()]);
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
