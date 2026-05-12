<?php

namespace Tests\Unit\Event;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Database as DatabaseMessage;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../app/init.php';

class QueuePublisherTest extends TestCase
{
    public function testDeleteMessageRoundTrip(): void
    {
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 123,
            'database' => 'mysql://shared',
        ]);
        $document = new Document([
            '$id' => 'user-1',
            '$collection' => 'users',
        ]);

        $message = new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $document,
            resource: 'resource-1',
            resourceType: 'functions',
            datetime: '2026-05-12 10:00:00',
            hourlyUsageRetentionDatetime: '2026-05-12 09:00:00',
        );

        $payload = $message->toArray();
        $rebuilt = DeleteMessage::fromArray($payload);

        $this->assertSame(DELETE_TYPE_DOCUMENT, $rebuilt->type);
        $this->assertSame('project-1', $rebuilt->project?->getId());
        $this->assertSame('user-1', $rebuilt->document?->getId());
        $this->assertSame($payload, $rebuilt->toArray());
    }

    public function testFunctionMessageRoundTripGeneratesEvents(): void
    {
        $project = new Document(['$id' => 'project-1']);
        $user = new Document(['$id' => 'user-1']);
        $function = new Document(['$id' => 'function-1']);
        $execution = new Document(['$id' => 'execution-1']);

        $message = FunctionMessage::fromEvent(
            event: 'users.[userId].create',
            params: ['userId' => 'user-1'],
            project: $project,
            user: $user,
            payload: ['name' => 'Ada'],
            platform: ['client' => 'web'],
        );

        $payload = $message->toArray();

        $this->assertContains('users.user-1.create', $payload['events']);
        $this->assertSame('Ada', $payload['payload']['name']);

        $rebuilt = FunctionMessage::fromArray((new FunctionMessage(
            project: $project,
            user: $user,
            function: $function,
            functionId: 'function-1',
            execution: $execution,
            type: 'http',
            jwt: 'jwt',
            payload: ['ok' => true],
            events: ['functions.function-1.create'],
            body: '{}',
            path: '/path',
            headers: ['x-test' => '1'],
            method: 'POST',
            platform: ['client' => 'web'],
        ))->toArray());

        $this->assertSame('function-1', $rebuilt->function?->getId());
        $this->assertSame('execution-1', $rebuilt->execution?->getId());
        $this->assertSame('POST', $rebuilt->method);
    }

    public function testDatabaseMessageRoundTrip(): void
    {
        $project = new Document(['$id' => 'project-1', 'database' => 'mysql://shared']);
        $database = new Document(['$id' => 'database-1']);
        $collection = new Document(['$id' => 'collection-1']);
        $document = new Document(['$id' => 'attribute-1']);

        $message = new DatabaseMessage(
            project: $project,
            type: DATABASE_TYPE_CREATE_ATTRIBUTE,
            database: $database,
            collection: $collection,
            document: $document,
            events: ['databases.database-1.collections.collection-1.attributes.attribute-1.create'],
        );

        $payload = $message->toArray();
        $rebuilt = DatabaseMessage::fromArray($payload);

        $this->assertSame(DATABASE_TYPE_CREATE_ATTRIBUTE, $rebuilt->type);
        $this->assertSame('database-1', $rebuilt->database?->getId());
        $this->assertSame($payload, $rebuilt->toArray());
    }

    public function testPublishersEnqueueToExpectedQueues(): void
    {
        $mock = new MockPublisher();
        $project = new Document(['$id' => 'project-1', '$sequence' => 123, 'database' => 'mysql://database-queue']);

        $deletePublisher = new DeletePublisher($mock, new Queue('delete-queue'));
        $functionPublisher = new FunctionPublisher($mock, new Queue('function-queue'));
        $databasePublisher = new DatabasePublisher($mock, new Queue(Event::DATABASE_QUEUE_NAME));

        $deletePublisher->enqueue(new DeleteMessage(project: $project, type: DELETE_TYPE_DOCUMENT));
        $functionPublisher->enqueue(new FunctionMessage(project: $project, type: 'schedule'));
        $databasePublisher->enqueue(new DatabaseMessage(project: $project, type: DATABASE_TYPE_DELETE_DATABASE));

        $this->assertCount(1, $mock->getEvents('delete-queue'));
        $this->assertCount(1, $mock->getEvents('function-queue'));
        $this->assertCount(1, $mock->getEvents('database-queue'));
    }
}
