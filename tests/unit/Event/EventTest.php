<?php

namespace Tests\Unit\Event;

use Appwrite\Event\Event;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Publisher;

require_once __DIR__ . '/../../../app/init.php';

class EventTest extends TestCase
{
    protected ?Event $object = null;
    protected string $queue = '';
    protected Publisher $publisher;

    public function setUp(): void
    {
        $this->publisher = new MockPublisher();

        $this->queue = 'v1-tests' . uniqid();
        $this->object = new Event($this->publisher);
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
        $this->assertCount(1, $this->publisher->getEvents($this->object->getQueue()));
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

        $event = Event::generateEvents('tables.[tableId].rows.[rowId].create', [
            'tableId' => 'chapters',
            'rowId' => 'prolog',
        ]);
        $this->assertCount(10, $event);

        $this->assertContains('tables.chapters.rows.prolog.create', $event);
        $this->assertContains('tables.chapters.rows.prolog', $event);
        $this->assertContains('tables.chapters.rows.*.create', $event);
        $this->assertContains('tables.chapters.rows.*', $event);
        $this->assertContains('tables.chapters', $event);
        $this->assertContains('tables.*.rows.prolog.create', $event);
        $this->assertContains('tables.*.rows.prolog', $event);
        $this->assertContains('tables.*.rows.*.create', $event);
        $this->assertContains('tables.*.rows.*', $event);
        $this->assertContains('tables.*', $event);

        $event = Event::generateEvents('databases.[databaseId].tables.[tableId].rows.[rowId].create', [
            'databaseId' => 'chaptersDB',
            'tableId' => 'chapters',
            'rowId' => 'prolog',
        ]);

        $this->assertCount(22, $event);
        $this->assertContains('databases.chaptersDB.tables.chapters.rows.prolog.create', $event);
        $this->assertContains('databases.chaptersDB.tables.chapters.rows.prolog', $event);
        $this->assertContains('databases.chaptersDB.tables.chapters.rows.*.create', $event);
        $this->assertContains('databases.chaptersDB.tables.chapters.rows.*', $event);
        $this->assertContains('databases.chaptersDB.tables.chapters', $event);
        $this->assertContains('databases.chaptersDB.tables.*.rows.prolog.create', $event);
        $this->assertContains('databases.chaptersDB.tables.*.rows.prolog', $event);
        $this->assertContains('databases.chaptersDB.tables.*', $event);
        $this->assertContains('databases.chaptersDB', $event);
        $this->assertContains('databases.*.tables.chapters.rows.prolog.create', $event);
        $this->assertContains('databases.*.tables.chapters.rows.prolog', $event);
        $this->assertContains('databases.*.tables.chapters', $event);
        $this->assertContains('databases.*.tables.*.rows.*.create', $event);
        $this->assertContains('databases.*.tables.*.rows.*', $event);
        $this->assertContains('databases.*.tables.*', $event);
        $this->assertContains('databases.*', $event);
        $this->assertContains('databases.*.tables.*.rows.prolog', $event);
        $this->assertContains('databases.*.tables.*.rows.prolog.create', $event);
        $this->assertContains('databases.*.tables.chapters.rows.*', $event);
        $this->assertContains('databases.*.tables.chapters.rows.*.create', $event);
        $this->assertContains('databases.chaptersDB.tables.*.rows.*', $event);
        $this->assertContains('databases.chaptersDB.tables.*.rows.*.create', $event);


        try {
            $event = Event::generateEvents('tables.[tableId].rows.[rowId].create', [
                'tableId' => 'chapters'
            ]);
            $this->fail();
        } catch (\Throwable $th) {
            $this->assertInstanceOf(InvalidArgumentException::class, $th, 'An invalid exception was thrown');
        }

        try {
            $event = Event::generateEvents('tables.[tableId].rows.[rowId].create');
            $this->fail();
        } catch (\Throwable $th) {
            $this->assertInstanceOf(InvalidArgumentException::class, $th, 'An invalid exception was thrown');
        }
    }
}
