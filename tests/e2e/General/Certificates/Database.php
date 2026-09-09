<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
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

        $name = 'certificate_test_' . bin2hex(random_bytes(6));
        $type = System::getEnv('_APP_DB_ADAPTER', 'postgresql');
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
            : $register->get('db', true);
        $adapter = match ($type) {
            'mariadb' => new MariaDB($connection),
            'mongodb' => new Mongo($connection),
            'mysql' => new MySQL($connection),
            'postgresql' => new Postgres($connection),
            default => throw new \InvalidArgumentException('Invalid database adapter'),
        };
        parent::__construct($adapter, new Cache(new NoCache()));
        $authorization = new Authorization();
        $authorization->disable();
        $this->setAuthorization($authorization);
        $this->setDatabase($name)->setNamespace('test')->setPreserveDates(true);
        $this->create();
        try {
            // The collections under test are created from their canonical definitions,
            // the same way a migration does, so this schema cannot drift from production.
            $collections = require __DIR__ . '/../../../../app/config/collections/platform.php';
            foreach (['rules', 'certificates'] as $id) {
                $this->createCollection(
                    $id,
                    array_map(fn (array $attribute) => new Document($attribute), $collections[$id]['attributes']),
                    array_map(fn (array $index) => new Document($index), $collections[$id]['indexes']),
                );
            }
            // The worker only reads a project to publish events. The canonical collection
            // carries sub-query filters that would pull in five more collections, so a stub is enough.
            $this->createCollection('projects');
            $this->createAttribute('projects', 'region', self::VAR_STRING, 128, false);
        } catch (\Throwable $error) {
            $this->delete();
            throw $error;
        }
    }
}
