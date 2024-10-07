<?php

namespace Tests\Unit\Event;

use Appwrite\Event\Event;
use Appwrite\URL\URL;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\DSN\DSN;
use Utopia\Queue;
use Utopia\Queue\Client;
use Utopia\System\System;

require_once __DIR__ . '/../../../app/init.php';

class EventTest extends TestCase
{
    protected ?Event $object = null;
    protected string $queue = '';

    public function setUp(): void
    {
        $fallbackForRedis = 'redis_main=' . URL::unparse([
            'scheme' => 'redis',
            'host' => System::getEnv('_APP_REDIS_HOST', 'redis'),
            'port' => System::getEnv('_APP_REDIS_PORT', '6379'),
            'user' => System::getEnv('_APP_REDIS_USER', ''),
            'pass' => System::getEnv('_APP_REDIS_PASS', ''),
        ]);

        $dsn = System::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis);
        $dsn = explode('=', $dsn);
        $dsn = $dsn[1] ?? '';
        $dsn = new DSN($dsn);
        $connection = new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
        $this->queue = 'v1-tests' . uniqid();
        $this->object = new Event($connection);
        $this->object->setClass('TestsV1');
        $this->object->setQueue($this->queue);
    }

    public function testQueue(): void
    {
        $this->assertEquals($this->queue, $this->object->getQueue());
        $this->object->setQueue('demo');
        $this->assertEquals('demo', $this->object->getQueue());
        $this->object->setQueue($this->queue);
    }

    public function testClass(): void
    {
        $this->assertEquals('TestsV1', $this->object->getClass());
        $this->object->setClass('TestsV2');
        $this->assertEquals('TestsV2', $this->object->getClass());
        $this->object->setClass('TestsV1');
    }

    public function testParams(): void
    {

        $this->object
            ->setParam('eventKey1', 'eventValue1')
            ->setParam('eventKey2', 'eventValue2');

        $this->object->trigger();
        $this->assertEquals('eventValue1', $this->object->getParam('eventKey1'));
        $this->assertEquals('eventValue2', $this->object->getParam('eventKey2'));
        $this->assertEquals(null, $this->object->getParam('eventKey3'));
        global $register;
        $pools = $register->get('pools');
        $client = new Client($this->object->getQueue(), $pools->get('queue')->pop()->getResource());
        $this->assertEquals($client->getQueueSize(), 1);
    }

    public function testReset(): void
    {
        $this->object
            ->setParam('eventKey1', 'eventValue1')
            ->setParam('eventKey2', 'eventValue2');

        $this->assertEquals('eventValue1', $this->object->getParam('eventKey1'));
        $this->assertEquals('eventValue2', $this->object->getParam('eventKey2'));

        $this->object->reset();

        $this->assertEquals(null, $this->object->getParam('eventKey1'));
        $this->assertEquals(null, $this->object->getParam('eventKey2'));
        $this->assertEquals(null, $this->object->getParam('eventKey3'));
    }

    public function testGenerateEvents(): void
    {
        $event = Event::generateEvents('users.[userId].create', [
            'userId' => 'torsten'
        ]);
        $this->assertCount(4, $event);
        $this->assertContains('users.torsten.create', $event);
        $this->assertContains('users.torsten', $event);
        $this->assertContains('users.*.create', $event);
        $this->assertContains('users.*', $event);

        $event = Event::generateEvents('users.[userId].update.email', [
            'userId' => 'torsten'
        ]);
        $this->assertCount(6, $event);
        $this->assertContains('users.torsten.update.email', $event);
        $this->assertContains('users.torsten.update', $event);
        $this->assertContains('users.torsten', $event);
        $this->assertContains('users.*.update.email', $event);
        $this->assertContains('users.*.update', $event);
        $this->assertContains('users.*', $event);

        $event = Event::generateEvents('collections.[collectionId].documents.[documentId].create', [
            'collectionId' => 'chapters',
            'documentId' => 'prolog',
        ]);
        $this->assertCount(10, $event);

        $this->assertContains('collections.chapters.documents.prolog.create', $event);
        $this->assertContains('collections.chapters.documents.prolog', $event);
        $this->assertContains('collections.chapters.documents.*.create', $event);
        $this->assertContains('collections.chapters.documents.*', $event);
        $this->assertContains('collections.chapters', $event);
        $this->assertContains('collections.*.documents.prolog.create', $event);
        $this->assertContains('collections.*.documents.prolog', $event);
        $this->assertContains('collections.*.documents.*.create', $event);
        $this->assertContains('collections.*.documents.*', $event);
        $this->assertContains('collections.*', $event);

        $event = Event::generateEvents('databases.[databaseId].collections.[collectionId].documents.[documentId].create', [
            'databaseId' => 'chaptersDB',
            'collectionId' => 'chapters',
            'documentId' => 'prolog',
        ]);

        $this->assertCount(22, $event);
        $this->assertContains('databases.chaptersDB.collections.chapters.documents.prolog.create', $event);
        $this->assertContains('databases.chaptersDB.collections.chapters.documents.prolog', $event);
        $this->assertContains('databases.chaptersDB.collections.chapters.documents.*.create', $event);
        $this->assertContains('databases.chaptersDB.collections.chapters.documents.*', $event);
        $this->assertContains('databases.chaptersDB.collections.chapters', $event);
        $this->assertContains('databases.chaptersDB.collections.*.documents.prolog.create', $event);
        $this->assertContains('databases.chaptersDB.collections.*.documents.prolog', $event);
        $this->assertContains('databases.chaptersDB.collections.*', $event);
        $this->assertContains('databases.chaptersDB', $event);
        $this->assertContains('databases.*.collections.chapters.documents.prolog.create', $event);
        $this->assertContains('databases.*.collections.chapters.documents.prolog', $event);
        $this->assertContains('databases.*.collections.chapters', $event);
        $this->assertContains('databases.*.collections.*.documents.*.create', $event);
        $this->assertContains('databases.*.collections.*.documents.*', $event);
        $this->assertContains('databases.*.collections.*', $event);
        $this->assertContains('databases.*', $event);
        $this->assertContains('databases.*.collections.*.documents.prolog', $event);
        $this->assertContains('databases.*.collections.*.documents.prolog.create', $event);
        $this->assertContains('databases.*.collections.chapters.documents.*', $event);
        $this->assertContains('databases.*.collections.chapters.documents.*.create', $event);
        $this->assertContains('databases.chaptersDB.collections.*.documents.*', $event);
        $this->assertContains('databases.chaptersDB.collections.*.documents.*.create', $event);


        try {
            $event = Event::generateEvents('collections.[collectionId].documents.[documentId].create', [
                'collectionId' => 'chapters'
            ]);
            $this->fail();
        } catch (\Throwable $th) {
            $this->assertInstanceOf(InvalidArgumentException::class, $th, 'An invalid exception was thrown');
        }

        try {
            $event = Event::generateEvents('collections.[collectionId].documents.[documentId].create');
            $this->fail();
        } catch (\Throwable $th) {
            $this->assertInstanceOf(InvalidArgumentException::class, $th, 'An invalid exception was thrown');
        }
    }
}
