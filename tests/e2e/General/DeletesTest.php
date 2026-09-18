<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Platform\Appwrite;
use Appwrite\Tests\Queue\InMemoryConnection;
use Appwrite\Workers\Jobs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client as MongoClient;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\System\System;

/**
 * A queued rule deletion can outlive its rule: the domain may already have been
 * recreated by the time the worker runs.
 *
 * Lives in e2e (not unit) because it drives the registered worker through a real
 * queue — see WorkerConcurrencyTest. The database is built here rather than taken
 * from the container because the container's cache is pool-backed, and popping a
 * pooled connection needs a Swoole coroutine the PHPUnit process is not in.
 */
final class DeletesTest extends TestCase
{
    private Database $database;
    private Redis $broker;
    private Queue $queue;
    private DeletePublisher $publisher;

    protected function setUp(): void
    {
        $this->database = $this->database();

        $connection = new InMemoryConnection();
        $this->broker = new Redis($connection, $connection);
        $this->queue = new Queue(
            System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME),
            'deletes-test-' . \bin2hex(\random_bytes(6)),
        );
        $this->publisher = new DeletePublisher($this->broker, $this->queue);
    }

    protected function tearDown(): void
    {
        $this->database->delete();
    }

    #[DataProvider('types')]
    public function testDeleteRule(array $attributes, string $expected): void
    {
        /**
         * Test for SUCCESS
         */
        $this->database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $this->enqueue($attributes);

        $this->assertSame([['example.com', $expected]], $this->runWorker());
        $this->assertTrue($this->database->getDocument('certificates', 'certificate')->isEmpty());
    }

    public static function types(): \Iterator
    {
        yield 'API' => [['type' => 'api'], ''];
        yield 'function' => [['type' => 'deployment', 'deploymentResourceType' => 'function'], 'function'];
        yield 'site' => [['type' => 'deployment', 'deploymentResourceType' => 'site'], 'site'];
    }

    public function testDeleteRecreatedRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $this->database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $this->enqueue();
        $this->createRule('replacement', '');

        $this->assertSame([], $this->runWorker());
        $this->assertTrue($this->database->getDocument('certificates', 'certificate')->isEmpty());
    }

    private function database(): Database
    {
        global $register;

        $name = 'deletes_test_' . \bin2hex(\random_bytes(6));
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

        $authorization = new Authorization();
        $authorization->disable();

        $database = new Database($adapter, new Cache(new NoCache()));
        $database->setAuthorization($authorization);
        $database->setDatabase($name)->setNamespace('test')->setPreserveDates(true);
        $database->create();

        try {
            // Built from the canonical definitions so the schema cannot drift from production.
            $collections = require __DIR__ . '/../../../app/config/collections/platform.php';
            foreach (['rules', 'certificates'] as $id) {
                $database->createCollection(
                    $id,
                    \array_map(fn (array $attribute) => new Document($attribute), $collections[$id]['attributes']),
                    \array_map(fn (array $index) => new Document($index), $collections[$id]['indexes']),
                );
            }
        } catch (\Throwable $error) {
            $database->delete();
            throw $error;
        }

        return $database;
    }

    private function createRule(string $id, string $certificateId, array $attributes = []): Document
    {
        return $this->database->createDocument('rules', new Document(\array_merge([
            '$id' => $id,
            'domain' => 'example.com',
            'certificateId' => $certificateId,
            'type' => 'api',
            'projectId' => 'console',
            'projectInternalId' => '0',
            'region' => 'default',
        ], $attributes)));
    }

    private function enqueue(array $attributes = []): void
    {
        $rule = $this->createRule('old-rule', 'certificate', $attributes);
        $this->database->deleteDocument('rules', $rule->getId());

        $this->publisher->enqueue(new DeleteMessage(
            type: DELETE_TYPE_DOCUMENT,
            document: $rule,
        ));
    }

    /**
     * Drain the queued deletion through the registered worker.
     *
     * @return list<array{string, ?string}> Domains the provider was asked to clean up.
     */
    private function runWorker(): array
    {
        global $container;

        $certificates = new class () implements Provider {
            /** @var list<array{string, ?string}> */
            public array $deleted = [];

            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                return null;
            }

            public function isInstantGeneration(string $domain, ?string $domainType): bool
            {
                return false;
            }

            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return true;
            }

            public function getCertificateStatus(string $domain, ?string $domainType): string
            {
                return Status::PENDING;
            }

            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
                $this->deleted[] = [$domain, $domainType];
            }
        };

        $resources = clone $container;
        $resources->set('publisher', fn () => $this->broker);
        $resources->set('pools', fn () => new Group());
        $resources->set('cache', fn () => new Cache(new NoCache()));
        $resources->set('certificates', fn () => $certificates);

        $worker = new Server(new KubernetesJob($this->broker, 1, $this->queue->namespace, $resources));
        $resources->set('bus', fn ($register) => $register->get('bus')->setResolver(
            fn (string $name) => $worker->context()->get($name)
        ), ['register']);
        $registerMessageResources = require __DIR__ . '/../../../app/init/worker/message.php';
        $worker->init()->action(function () use ($worker, $registerMessageResources): void {
            $context = $worker->context();
            $registerMessageResources($context);
            $context->set('dbForPlatform', fn () => $this->database);
        });

        $platform = new Appwrite();
        $platform->setWorker($worker);
        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'deletes',
            'jobs' => Jobs::resolve(['deletes'], Config::getParam('workers'), System::getEnv(...)),
        ]);

        $error = null;
        $worker->shutdown()->action(fn () => $worker->stop());
        $worker->error()->inject('error')->action(function (\Throwable $failure) use ($worker, &$error): void {
            $error = $failure;
            $worker->stop();
        });
        $worker->start();

        if ($error !== null) {
            throw $error;
        }

        return $certificates->deleted;
    }
}
