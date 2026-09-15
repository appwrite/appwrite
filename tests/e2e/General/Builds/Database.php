<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client as MongoClient;
use Utopia\System\System;

final class Database extends UtopiaDatabase
{
    public function __construct()
    {
        global $register;

        $name = 'build_test_' . bin2hex(random_bytes(6));
        $type = System::getEnv('_APP_TEST_DB_ADAPTER', System::getEnv('_APP_DB_ADAPTER', 'postgresql'));
        // MongoDB binds the database to its client; setDatabase() only scopes the adapter.
        $connection = $type === 'mongodb'
            ? new MongoClient(
                $name,
                System::getEnv('_APP_DB_HOST', ''),
                (int) System::getEnv('_APP_DB_PORT', ''),
                System::getEnv('_APP_DB_USER', ''),
                System::getEnv('_APP_DB_PASS', ''),
                false,
            )
            : ($type === 'sqlite' ? new \PDO\Sqlite('sqlite::memory:', null, null, array_replace(SQLite::getPDOAttributes(), [\PDO::ATTR_PERSISTENT => false])) : $register->get('db', true));
        $adapter = match ($type) {
            'mariadb' => new MariaDB($connection),
            'mongodb' => new Mongo($connection),
            'mysql' => new MySQL($connection),
            'postgresql' => new Postgres($connection),
            'sqlite' => new SQLite($connection),
            default => throw new \InvalidArgumentException('Invalid database adapter'),
        };
        parent::__construct($adapter, new Cache(new Memory()));
        $authorization = new Authorization();
        $authorization->disable();
        $this->setAuthorization($authorization);
        $this->setDatabase($name)->setNamespace('test');
        $this->create();
        try {
            // The collections under test are created from their canonical definitions,
            // the same way a migration does, so this schema cannot drift from production.
            $collections = Config::getParam('collections');
            foreach (['projects' => ['functions', 'sites', 'deployments', 'variables'], 'console' => ['rules', 'schedules']] as $scope => $ids) {
                foreach ($ids as $id) {
                    $this->createCollection(
                        $id,
                        array_map(fn (array $attribute) => new Document($attribute), $collections[$scope][$id]['attributes']),
                        array_map(fn (array $index) => new Document($index), $collections[$scope][$id]['indexes']),
                    );
                }
            }
        } catch (\Throwable $error) {
            $this->delete();
            throw $error;
        }
    }
}
