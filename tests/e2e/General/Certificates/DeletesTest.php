<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Platform\Appwrite;
use Appwrite\Tests\Queue\InMemoryConnection;
use Appwrite\Workers\Jobs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\System\System;

final class DeletesTest extends TestCase
{
    private Database $database;
    private Redis $broker;
    private Queue $queue;
    private DeletePublisher $publisher;

    protected function setUp(): void
    {
        $this->database = new Database();
        $connection = new InMemoryConnection();
        $this->broker = new Redis($connection, $connection);
        $this->queue = new Queue(
            System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME),
            'certificate-test-' . bin2hex(random_bytes(6)),
        );
        $this->publisher = new DeletePublisher($this->broker, $this->queue);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
    }

    #[DataProvider('types')]
    public function testDeleteRule(array $attributes, string $expected): void
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $rule = $this->enqueue($attributes);

        $deleted = $this->runWorker($provider);

        $this->assertCount(1, $deleted);
        $this->assertSame($rule->getId(), $deleted[0]['$id']);
        $this->assertSame($rule->getAttribute('domain'), $deleted[0]['domain']);
        $this->assertSame([['example.com', $expected]], $provider->deleted);
        $this->assertTrue($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    public static function types(): \Iterator
    {
        yield 'API' => [['type' => 'api', 'deploymentResourceType' => ''], 'api']; // Persisted default for API rules
        yield 'function' => [['type' => 'deployment', 'deploymentResourceType' => 'function'], 'function'];
        yield 'site' => [['type' => 'deployment', 'deploymentResourceType' => 'site'], 'site'];
        yield 'redirect' => [['type' => 'redirect', 'deploymentResourceType' => 'site'], 'site'];
    }

    public function testDeleteRecreatedRule(): void
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $this->enqueue();
        $database->createDocument('rules', new Document([
            '$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => 'certificate',
            'projectId' => 'project', 'projectInternalId' => '7', 'region' => 'default',
        ]));
        $this->assertSame([], $this->runWorker($provider));
        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    #[DataProvider('replacements')]
    public function testDeleteUnreferencedCertificate(string $certificateId): void
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate', 'domain' => 'example.com']));
        if ($certificateId !== '') {
            $database->createDocument('certificates', new Document(['$id' => $certificateId, 'domain' => 'example.com']));
        }
        $this->enqueue();
        $database->createDocument('rules', new Document([
            '$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => $certificateId,
            'projectId' => 'project', 'projectInternalId' => '7', 'region' => 'default',
        ]));
        $this->assertSame([], $this->runWorker($provider));

        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('rules', 'replacement')->isEmpty());
        if ($certificateId !== '') {
            $this->assertFalse($database->getDocument('certificates', $certificateId)->isEmpty());
        }
        $this->assertTrue($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    public static function replacements(): \Iterator
    {
        yield 'new certificate' => ['new-certificate'];
        yield 'no certificate yet' => [''];
    }

    public function testDeleteReferencedCertificate(): void
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->database;
        $provider = new Provider();
        $database->createDocument('certificates', new Document(['$id' => 'certificate']));
        $this->enqueue();
        $database->createDocument('rules', new Document([
            '$id' => 'replacement', 'domain' => 'example.com', 'certificateId' => '',
            'projectId' => 'project', 'projectInternalId' => '7', 'region' => 'default',
        ]));
        $database->createDocument('rules', new Document([
            '$id' => 'other', 'domain' => 'other.example.com', 'certificateId' => 'certificate',
            'projectId' => 'project', 'projectInternalId' => '7', 'region' => 'default',
        ]));

        $this->assertSame([], $this->runWorker($provider));

        $this->assertSame([], $provider->deleted);
        $this->assertFalse($database->getDocument('certificates', 'certificate')->isEmpty());
    }

    private function enqueue(array $attributes = []): Document
    {
        $rule = $this->database->createDocument('rules', new Document(array_merge([
            '$id' => 'old-rule', 'domain' => 'example.com', 'certificateId' => 'certificate',
            'type' => 'api', 'projectId' => 'console', 'projectInternalId' => '0', 'region' => 'default',
        ], $attributes)));
        $this->database->deleteDocument('rules', $rule->getId());
        $this->assertNotFalse($this->publisher->enqueue(new DeleteMessage(
            type: DELETE_TYPE_DOCUMENT,
            document: $rule,
        )));

        return $rule;
    }

    /** @return list<array<string, mixed>> */
    private function runWorker(Provider $provider): array
    {
        global $container;

        // Use the registered worker and per-message resources. The broker keeps
        // the stale job queued until its replacement has been persisted.
        $resources = clone $container;
        $resources->set('publisher', fn () => $this->broker);
        $resources->set('pools', fn () => new Group());
        $resources->set('cache', fn () => new Cache(new NoCache()));
        $resources->set('certificates', fn () => $provider);

        $deletion = new Deletion();
        $bus = (new Bus())->subscribe($deletion);
        $worker = new Server(new KubernetesJob($this->broker, 1, $this->queue->namespace, $resources));
        $register = require __DIR__ . '/../../../../app/init/worker/message.php';
        $worker->init()->action(function () use ($worker, $register, $bus): void {
            $context = $worker->context();
            $register($context);
            $context->set('dbForPlatform', fn () => $this->database);
            $context->set('bus', fn () => $bus->setResolver($context->get(...)));
        });

        $platform = new Appwrite();
        $platform->setWorker($worker);
        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'deletes',
            'jobs' => Jobs::resolve(['deletes'], Config::getParam('workers'), System::getEnv(...)),
        ]);

        $processed = 0;
        $error = null;
        $worker->shutdown()->action(function () use ($worker, &$processed): void {
            $processed++;
            $worker->stop();
        });
        $worker->error()->inject('error')->action(function (\Throwable $failure) use ($worker, &$error): void {
            $error = $failure;
            $worker->stop();
        });
        $worker->start();

        if ($error !== null) {
            throw $error;
        }
        $this->assertSame(1, $processed);
        $this->assertSame(0, $this->publisher->getSize());
        $this->assertSame(0, $this->publisher->getSize(failed: true));

        return $deletion->rules;
    }
}
