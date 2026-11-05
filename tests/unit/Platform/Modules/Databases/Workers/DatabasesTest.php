<?php

namespace Tests\Unit\Platform\Modules\Databases\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Logger\Log;
use Utopia\Queue\Message;

class DatabasesTest extends TestCase
{
    /**
     * Test that a Duplicate exception during createRelationship is handled
     * gracefully by setting the attribute status to 'available' instead of 'failed'.
     *
     * This reproduces Sentry issue CLOUD-3JA4 where queue retries cause
     * "Related attribute already exists" errors.
     */
    public function testCreateRelationshipDuplicateIsHandledGracefully(): void
    {
        $databaseSequence = '1';
        $collectionSequence = '2';
        $relatedCollectionSequence = '3';
        $attributeId = $databaseSequence . '_' . $collectionSequence . '_testRelation';

        $attribute = new Document([
            '$id' => $attributeId,
            '$sequence' => $attributeId,
            'key' => 'testRelation',
            'type' => Database::VAR_RELATIONSHIP,
            'status' => 'processing',
            'size' => 0,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'format' => '',
            'formatOptions' => [],
            'filters' => [],
            'options' => [
                'relatedCollection' => 'relatedCol',
                'relationType' => Database::RELATION_ONE_TO_ONE,
                'twoWay' => false,
                'twoWayKey' => 'reverse',
                'onDelete' => Database::RELATION_MUTATE_CASCADE,
            ],
        ]);

        $collection = new Document([
            '$id' => 'testCol',
            '$sequence' => $collectionSequence,
        ]);

        $relatedCollection = new Document([
            '$id' => 'relatedCol',
            '$sequence' => $relatedCollectionSequence,
        ]);

        $database = new Document([
            '$id' => 'testDb',
            '$sequence' => $databaseSequence,
        ]);

        $project = new Document([
            '$id' => 'testProject',
        ]);

        // Mock dbForProject
        $dbForProject = $this->createMock(Database::class);

        // getDocument calls
        $dbForProject->method('getDocument')
            ->willReturnCallback(function (string $collection, string $id) use ($attribute, $relatedCollection, $databaseSequence) {
                if ($collection === 'attributes' && $id === $attribute->getId()) {
                    return $attribute;
                }
                if ($collection === 'database_' . $databaseSequence) {
                    return $relatedCollection;
                }
                return new Document();
            });

        // createRelationship should throw DuplicateException (simulating a queue retry)
        $dbForProject->method('createRelationship')
            ->willThrowException(new DuplicateException('Related attribute already exists'));

        // Expect updateDocument to be called with status 'available' (NOT 'failed')
        $dbForProject->expects($this->once())
            ->method('updateDocument')
            ->with(
                'attributes',
                $attribute->getId(),
                $this->callback(function (Document $doc) {
                    return $doc->getAttribute('status') === 'available';
                })
            )
            ->willReturnArgument(2);

        $dbForProject->method('purgeCachedDocument')->willReturn(true);
        $dbForProject->method('purgeCachedCollection')->willReturn(true);

        // Mock dbForPlatform
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')
            ->willReturn($project);

        // Mock Realtime
        $queueForRealtime = $this->createMock(Realtime::class);
        $queueForRealtime->method('setProject')->willReturnSelf();
        $queueForRealtime->method('setSubscribers')->willReturnSelf();
        $queueForRealtime->method('setEvent')->willReturnSelf();
        $queueForRealtime->method('setParam')->willReturnSelf();
        $queueForRealtime->method('setPayload')->willReturnSelf();
        $queueForRealtime->method('trigger')->willReturn(true);

        // Mock Log
        $log = $this->createMock(Log::class);

        // Mock Message
        $message = $this->createMock(Message::class);
        $message->method('getPayload')->willReturn([
            'type' => DATABASE_TYPE_CREATE_ATTRIBUTE,
            'document' => $attribute->getArrayCopy(),
            'collection' => $collection->getArrayCopy(),
            'database' => $database->getArrayCopy(),
        ]);

        // Execute the worker action - should NOT throw
        $worker = new Databases();
        $worker->action($message, $project, $dbForPlatform, $dbForProject, $queueForRealtime, $log);
    }

    /**
     * Test that a Duplicate exception during two-way createRelationship
     * sets both the primary and related attribute status to 'available'.
     */
    public function testCreateTwoWayRelationshipDuplicateIsHandledGracefully(): void
    {
        $databaseSequence = '1';
        $collectionSequence = '2';
        $relatedCollectionSequence = '3';
        $attributeId = $databaseSequence . '_' . $collectionSequence . '_testRelation';
        $relatedAttributeId = $databaseSequence . '_' . $relatedCollectionSequence . '_reverse';

        $attribute = new Document([
            '$id' => $attributeId,
            '$sequence' => $attributeId,
            'key' => 'testRelation',
            'type' => Database::VAR_RELATIONSHIP,
            'status' => 'processing',
            'size' => 0,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'format' => '',
            'formatOptions' => [],
            'filters' => [],
            'options' => [
                'relatedCollection' => 'relatedCol',
                'relationType' => Database::RELATION_ONE_TO_ONE,
                'twoWay' => true,
                'twoWayKey' => 'reverse',
                'onDelete' => Database::RELATION_MUTATE_CASCADE,
            ],
        ]);

        $relatedAttribute = new Document([
            '$id' => $relatedAttributeId,
            '$sequence' => $relatedAttributeId,
            'key' => 'reverse',
            'type' => Database::VAR_RELATIONSHIP,
            'status' => 'processing',
        ]);

        $collection = new Document([
            '$id' => 'testCol',
            '$sequence' => $collectionSequence,
        ]);

        $relatedCollection = new Document([
            '$id' => 'relatedCol',
            '$sequence' => $relatedCollectionSequence,
        ]);

        $database = new Document([
            '$id' => 'testDb',
            '$sequence' => $databaseSequence,
        ]);

        $project = new Document([
            '$id' => 'testProject',
        ]);

        // Mock dbForProject
        $dbForProject = $this->createMock(Database::class);

        $dbForProject->method('getDocument')
            ->willReturnCallback(function (string $collection, string $id) use ($attribute, $relatedAttribute, $relatedCollection, $databaseSequence) {
                if ($collection === 'attributes' && $id === $attribute->getId()) {
                    return $attribute;
                }
                if ($collection === 'attributes' && $id === $relatedAttribute->getId()) {
                    return $relatedAttribute;
                }
                if ($collection === 'database_' . $databaseSequence) {
                    return $relatedCollection;
                }
                return new Document();
            });

        // createRelationship throws DuplicateException
        $dbForProject->method('createRelationship')
            ->willThrowException(new DuplicateException('Related attribute already exists'));

        // Expect updateDocument to be called twice - once for each attribute, both with 'available' status
        $updateCalls = [];
        $dbForProject->expects($this->exactly(2))
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $doc) use (&$updateCalls) {
                $updateCalls[] = [
                    'collection' => $collection,
                    'id' => $id,
                    'status' => $doc->getAttribute('status'),
                ];
                return $doc;
            });

        $dbForProject->method('purgeCachedDocument')->willReturn(true);
        $dbForProject->method('purgeCachedCollection')->willReturn(true);

        // Mock dbForPlatform
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')->willReturn($project);

        // Mock Realtime
        $queueForRealtime = $this->createMock(Realtime::class);
        $queueForRealtime->method('setProject')->willReturnSelf();
        $queueForRealtime->method('setSubscribers')->willReturnSelf();
        $queueForRealtime->method('setEvent')->willReturnSelf();
        $queueForRealtime->method('setParam')->willReturnSelf();
        $queueForRealtime->method('setPayload')->willReturnSelf();
        $queueForRealtime->method('trigger')->willReturn(true);

        // Mock Log
        $log = $this->createMock(Log::class);

        // Mock Message
        $message = $this->createMock(Message::class);
        $message->method('getPayload')->willReturn([
            'type' => DATABASE_TYPE_CREATE_ATTRIBUTE,
            'document' => $attribute->getArrayCopy(),
            'collection' => $collection->getArrayCopy(),
            'database' => $database->getArrayCopy(),
        ]);

        // Execute - should NOT throw
        $worker = new Databases();
        $worker->action($message, $project, $dbForPlatform, $dbForProject, $queueForRealtime, $log);

        // Verify both attributes were marked as 'available'
        $this->assertCount(2, $updateCalls);
        foreach ($updateCalls as $call) {
            $this->assertEquals('attributes', $call['collection']);
            $this->assertEquals('available', $call['status']);
        }
    }
}
