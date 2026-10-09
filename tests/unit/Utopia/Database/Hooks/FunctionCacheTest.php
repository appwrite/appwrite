<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Functions\EventProcessor;
use Appwrite\Utopia\Database\Hooks\FunctionCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utopia\Database\Adapter\ConnectedMemory;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\Created;
use Utopia\Database\Event\Document\Deleted;
use Utopia\Database\Event\Document\Updated;
use Utopia\Database\Event\Domain;
use Utopia\Database\Validator\Authorization;

final class FunctionCacheTest extends TestCase
{
    private Database $database;

    private Document $project;

    protected function setUp(): void
    {
        $this->database = (new Database((new ConnectedMemory())->setHostname('db1'), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_1')
            ->setAuthorization(new Authorization());
        $this->project = new Document(['$id' => 'project1', '$sequence' => '1']);

        $this->database->getAuthorization()->skip(function (): void {
            $this->database->create();
            $this->database->createCollection(Collection::create(
                id: 'functions',
                attributes: [Attribute::string('events', size: 256, array: true)],
            ));
        });
    }

    public static function functionWrites(): \Iterator
    {
        $function = new Document(['$id' => 'function1', '$collection' => 'functions']);

        yield 'create' => [new Created('functions', $function)];
        yield 'update' => [new Updated('functions', $function)];
        yield 'delete' => [new Deleted('functions', $function)];
    }

    #[DataProvider('functionWrites')]
    public function testAFunctionWritePurgesTheEventsTheProcessorCached(Domain $event): void
    {
        $processor = new EventProcessor();
        $this->assertSame([], $processor->getFunctionsEvents($this->project, $this->database));

        $this->addFunction(['users.*.create']);
        $this->assertSame([], $processor->getFunctionsEvents($this->project, $this->database), 'the processor reads its cached events');

        (new FunctionCache($this->project, $this->database))->handle($event);

        $this->assertSame(['users.*.create' => true], $processor->getFunctionsEvents($this->project, $this->database));
    }

    public function testAWriteToAnotherCollectionKeepsTheCachedEvents(): void
    {
        $processor = new EventProcessor();
        $processor->getFunctionsEvents($this->project, $this->database);
        $this->addFunction(['users.*.create']);

        (new FunctionCache($this->project, $this->database))->handle(new Created('users', new Document(['$id' => 'user1', '$collection' => 'users'])));

        $this->assertSame([], $processor->getFunctionsEvents($this->project, $this->database));
    }

    public static function handledEvents(): \Iterator
    {
        yield 'create' => [Event::DocumentCreate, true];
        yield 'update' => [Event::DocumentUpdate, true];
        yield 'delete' => [Event::DocumentDelete, true];
        yield 'read' => [Event::DocumentRead, false];
        yield 'bulk create' => [Event::DocumentsCreate, false];
    }

    #[DataProvider('handledEvents')]
    public function testItSelectsOnlySingleDocumentWrites(Event $event, bool $handled): void
    {
        $this->assertSame($handled, (new FunctionCache($this->project, $this->database))->handles($event));
    }

    /**
     * @param list<string> $events
     */
    private function addFunction(array $events): void
    {
        $this->database->getAuthorization()->skip(fn () => $this->database->silent(
            fn () => $this->database->createDocument('functions', new Document(['$id' => 'function1', 'events' => $events]))
        ));
    }
}
