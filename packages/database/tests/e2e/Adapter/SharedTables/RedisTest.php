<?php

namespace Tests\E2E\Adapter\SharedTables;

use Tests\E2E\Adapter\RedisTest as BaseRedisTest;
use Utopia\Database\Database;

class RedisTest extends BaseRedisTest
{
    /**
     * Apply shared-tables config and the empty namespace BEFORE
     * Database::create() is called. Patching after-the-fact would write
     * the bootstrap keys (dbs, cols, metadata) under the per-run namespace
     * and leak them when teardown only scrubs the empty-namespace pattern.
     */
    protected function configureDatabase(Database $database): void
    {
        $database->setSharedTables(true);
        $database->setTenant(999);
        $database->setNamespace('');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Re-assert tenancy on every test method since some inherited
        // scope tests mutate the bound database. Namespace and shared
        // mode are already configured by configureDatabase().
        $database = $this->getDatabase();
        $database->setSharedTables(true);
        $database->setTenant(999);
        $database->setNamespace('');
    }
}
