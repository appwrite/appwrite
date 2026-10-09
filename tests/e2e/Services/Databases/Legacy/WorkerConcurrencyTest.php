<?php

namespace Tests\E2E\Services\Databases\Legacy;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PDO;
use Utopia\Queue\Message;
use Utopia\System\System;

class WorkerConcurrencyTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConcurrentDdlPreservesSchemaAndReleasesAFailedHandler(): void
    {
        $dsn = getenv('_APP_DDL_TEST_DSN');
        if (!$dsn && System::getEnv('_APP_DB_ADAPTER') !== 'mariadb') {
            $this->markTestSkipped('Runs in the MariaDB E2E matrix, or with _APP_DDL_TEST_DSN for a local MySQL drill.');
        }
        $user = $dsn ? (getenv('_APP_DDL_TEST_USER') ?: 'root') : System::getEnv('_APP_DB_USER');
        $password = $dsn ? (getenv('_APP_DDL_TEST_PASSWORD') ?: '') : System::getEnv('_APP_DB_PASS');
        $dsn = $dsn ?: 'mysql:host=' . System::getEnv('_APP_DB_HOST') . ';port=' . System::getEnv('_APP_DB_PORT', '3306');
        $schema = 'ddl_' . bin2hex(random_bytes(6));
        $cache = new Cache(new Memory());
        $connect = function () use ($dsn, $user, $password, $schema, $cache): Database {
            $adapter = new MariaDB(new PDO($dsn, $user, $password, [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]));
            $adapter->setDatabase($schema);
            $adapter->setNamespace('test');
            $db = new Database($adapter, $cache);
            $db->getAuthorization()->disable();
            return $db->disableValidation();
        };
        $db = $connect();
        $db->create();
        try {
            $db->createCollection('projects');
            $project = $db->createDocument('projects', new Document(['$id' => 'project']));
            $attributes = Config::getParam('collections')['projects']['attributes']['attributes'];
            $db->createCollection('attributes', array_map(fn (array $attribute) => new Document($attribute), $attributes));
            $db->createCollection('database_1_collection_1');
            foreach (['first', 'second', 'broken', 'after'] as $id) {
                $db->createDocument('attributes', new Document([
                    '$id' => $id, 'key' => $id, 'type' => Database::VAR_STRING,
                    'size' => 64, 'required' => false, 'status' => 'processing',
                ]));
            }

            $errors = [];
            $entered = [];
            $worker = new Databases();
            Coroutine\run(function () use ($worker, $project, $connect, &$errors, &$entered): void {
                $started = new Channel(1);
                $release = new Channel(1);
                $done = new Channel(4);
                $run = function (string $id) use ($worker, $project, $connect, &$errors, &$entered, $started, $release, $done): void {
                    $connection = $connect();
                    $connection->getAdapter()->before(Database::EVENT_ATTRIBUTE_CREATE, 'barrier', function (string $sql) use ($id, &$entered, $started, $release): string {
                        $entered[] = $id;
                        if ($id === 'first') {
                            $started->push(true);
                            if (!$release->pop(5)) {
                                throw new \RuntimeException('DDL barrier timed out');
                            }
                        }
                        if ($id === 'broken') {
                            throw new \RuntimeException('injected DDL failure');
                        }
                        return $sql;
                    });
                    try {
                        $worker->action(
                            (new Message())->setQueue('v1-database')->setPayload([
                                'type' => DATABASE_TYPE_CREATE_ATTRIBUTE,
                                'database' => ['$id' => 'database', '$sequence' => '1'],
                                'collection' => ['$id' => 'table', '$sequence' => '1'],
                                'document' => ['$id' => $id],
                            ]),
                            $project,
                            $connection,
                            $connection,
                            fn () => $connection,
                            (new Realtime())->setPaused(true),
                        );
                    } catch (\Throwable $error) {
                        $errors[$id] = $error->getMessage();
                    } finally {
                        $done->push(true);
                    }
                };
                Coroutine::create(fn () => $run('first'));
                $started->pop(5);
                foreach (['second', 'broken', 'after'] as $id) {
                    Coroutine::create(fn () => $run($id));
                }
                $release->push(true);
                for ($i = 0; $i < 4; $i++) {
                    $done->pop(5);
                }
            });

            $this->assertSame(['broken' => 'injected DDL failure'], $errors);
            $db->purgeCachedCollection('database_1_collection_1');
            $keys = array_map(fn (Document $a) => $a->getId(), $db->getCollection('database_1_collection_1')->getAttribute('attributes'));
            sort($keys);
            $this->assertSame(['after', 'first', 'second'], $keys);
            foreach (['first', 'second', 'after'] as $id) {
                $this->assertSame('available', $db->getDocument('attributes', $id)->getAttribute('status'));
            }
            $this->assertSame('failed', $db->getDocument('attributes', 'broken')->getAttribute('status'));
            // Exercise the physical schema too, not only the metadata written above.
            $row = $db->createDocument('database_1_collection_1', new Document([
                '$id' => 'row', 'first' => 'one', 'second' => 'two', 'after' => 'three',
            ]));
            $this->assertSame('two', $row->getAttribute('second'));
            $this->assertSame('three', $row->getAttribute('after'));
        } finally {
            $db->delete();
        }
    }
}
