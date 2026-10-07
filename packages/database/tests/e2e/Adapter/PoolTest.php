<?php

namespace Tests\E2E\Adapter;

use Redis;
use ReflectionClass;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Pool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\PDO;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

class PoolTest extends Base
{
    public static ?Database $database = null;

    /**
     * @var UtopiaPool<MySQL>
     */
    protected static UtopiaPool $pool;
    protected static string $namespace;

    /**
     * @return Database
     * @throws Exception
     * @throws Duplicate
     * @throws Limit
     */
    public function getDatabase(): Database
    {
        if (!is_null(self::$database)) {
            return self::$database;
        }

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
        $cache = new Cache(new RedisAdapter($redis));

        $pool = new UtopiaPool(new Stack(), 'mysql', 10, function () {
            $dbHost = 'mysql';
            $dbPort = '3307';
            $dbUser = 'root';
            $dbPass = 'password';

            return new MySQL(new PDO(
                dsn: "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                username: $dbUser,
                password: $dbPass,
                config: MySQL::getPDOAttributes(),
            ));
        }, timeout: 0.0);

        $database = new Database(new Pool($pool), $cache);

        $database
            ->setAuthorization(self::$authorization)
            ->setDatabase('utopiaTests')
            ->setNamespace(static::$namespace = 'myapp_' . uniqid());

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        self::$pool = $pool;

        return self::$database = $database;
    }

    protected function deleteColumn(string $collection, string $column): bool
    {
        $sqlTable = "`" . $this->getDatabase()->getDatabase() . "`.`" . $this->getDatabase()->getNamespace() . "_" . $collection . "`";
        $sql = "ALTER TABLE {$sqlTable} DROP COLUMN `{$column}`";

        self::$pool->use(function (Adapter $adapter) use ($sql) {
            // Hack to get adapter PDO reference
            $class = new ReflectionClass($adapter);
            $property = $class->getProperty('pdo');
            $property->setAccessible(true);
            $pdo = $property->getValue($adapter);
            $pdo->exec($sql);
        });

        return true;
    }

    protected function deleteIndex(string $collection, string $index): bool
    {
        $sqlTable = "`" . $this->getDatabase()->getDatabase() . "`.`" . $this->getDatabase()->getNamespace() . "_" . $collection . "`";
        $sql = "DROP INDEX `{$index}` ON {$sqlTable}";

        self::$pool->use(function (Adapter $adapter) use ($sql) {
            // Hack to get adapter PDO reference
            $class = new ReflectionClass($adapter);
            $property = $class->getProperty('pdo');
            $property->setAccessible(true);
            $pdo = $property->getValue($adapter);
            $pdo->exec($sql);
        });

        return true;
    }

    /**
     * Execute raw SQL via the pool using reflection to access the adapter's PDO.
     *
     * @param string $sql
     * @param array<string, mixed> $binds
     */
    private function execRawSQL(string $sql, array $binds = []): void
    {
        self::$pool->use(function (Adapter $adapter) use ($sql, $binds) {
            $class = new ReflectionClass($adapter);
            $property = $class->getProperty('pdo');
            $property->setAccessible(true);
            $pdo = $property->getValue($adapter);
            $stmt = $pdo->prepare($sql);
            foreach ($binds as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
        });
    }

    /**
     * Test that orphaned permission records from a previous failed delete
     * don't block document recreation. The createDocument method should
     * clean up orphaned perms and retry.
     */
    public function testOrphanedPermissionsRecovery(): void
    {
        $database = $this->getDatabase();
        $collection = 'orphanedPermsRecovery';

        $database->createCollection($collection);
        $database->createAttribute($collection, 'title', Database::VAR_STRING, 128, true);

        // Step 1: Create a document with permissions
        $doc = $database->createDocument($collection, new Document([
            '$id' => 'orphan_test',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'title' => 'Original',
        ]));
        $this->assertEquals('orphan_test', $doc->getId());

        // Step 2: Delete the document normally (cleans up both doc and perms)
        $database->deleteDocument($collection, 'orphan_test');
        $deleted = $database->getDocument($collection, 'orphan_test');
        $this->assertTrue($deleted->isEmpty());

        // Step 3: Manually re-insert orphaned permission rows (simulating a partial delete failure)
        $namespace = $this->getDatabase()->getNamespace();
        $dbName = $this->getDatabase()->getDatabase();
        $permsTable = "`{$dbName}`.`{$namespace}_{$collection}_perms`";

        $this->execRawSQL(
            "INSERT INTO {$permsTable} (_type, _permission, _document) VALUES (:type, :perm, :doc)",
            [':type' => 'read', ':perm' => 'any', ':doc' => 'orphan_test']
        );
        $this->execRawSQL(
            "INSERT INTO {$permsTable} (_type, _permission, _document) VALUES (:type, :perm, :doc)",
            [':type' => 'update', ':perm' => 'any', ':doc' => 'orphan_test']
        );

        // Step 4: Recreate a document with the same ID - should succeed by cleaning up orphaned perms
        $newDoc = $database->createDocument($collection, new Document([
            '$id' => 'orphan_test',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'title' => 'Recreated',
        ]));
        $this->assertEquals('orphan_test', $newDoc->getId());
        $this->assertEquals('Recreated', $newDoc->getAttribute('title'));

        // Verify the document can be fetched
        $found = $database->getDocument($collection, 'orphan_test');
        $this->assertFalse($found->isEmpty());
        $this->assertEquals('Recreated', $found->getAttribute('title'));

        $database->deleteCollection($collection);
    }
}
