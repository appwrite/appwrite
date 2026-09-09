<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Presences;

use Appwrite\Database\Factory;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Presences\HTTP\Update;
use Appwrite\Tests\Queue\InMemoryConnection;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Broker\Redis;

final class PresenceDeletionTest extends TestCase
{
    public function testUpdateDuringDeletion(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $error = null;
        \Swoole\Coroutine\run(function () use (&$error): void {
            try {
                $this->updateDuringDeletion();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    private function updateDuringDeletion(): void
    {
        global $register;

        $authorization = new Authorization();
        $authorization->disable();
        $db = (new Factory($register->get('pools'), new Cache(new Memory()), $authorization))->platform();
        $db->setNamespace('presences_' . ID::unique());
        $db->create();
        $collections = [Database::METADATA];

        try {
            $attributes = [];
            foreach (['userId', 'status', 'source'] as $name) {
                $attributes[] = new Document([
                    '$id' => $name,
                    'type' => Database::VAR_STRING,
                    'size' => 128,
                    'required' => false,
                    'array' => false,
                ]);
            }
            $db->createCollection('presenceLogs', $attributes);
            $collections[] = 'presenceLogs';
            $presenceId = ID::unique();
            $userId = ID::unique();
            $db->createDocument('presenceLogs', new Document([
                '$id' => $presenceId,
                '$permissions' => ['read("any")'],
                'userId' => $userId,
                'status' => 'online',
                'source' => 'HTTP',
            ]));

            // Realtime disconnect or expiry removes the row after the handler's
            // initial lookup, before updateDocument locks and rereads it.
            $db->on(Database::EVENT_DOCUMENT_READ, 'delete', function (string $event, Document $document) use ($db, $presenceId): void {
                if ($document->getId() !== $presenceId) {
                    return;
                }
                $db->on(Database::EVENT_DOCUMENT_READ, 'delete', null);
                $db->deleteDocument('presenceLogs', $presenceId);
            });

            $connection = new InMemoryConnection();
            $events = new Event(new Redis($connection, $connection));
            $exception = null;

            try {
                (new Update())->action(
                    presenceId: $presenceId,
                    userId: null,
                    status: 'away',
                    expiresAt: null,
                    metadata: null,
                    permissions: null,
                    purge: false,
                    response: new Response(new \Swoole\Http\Response()),
                    dbForProject: $db,
                    user: new User(['$id' => $userId]),
                    authorization: $authorization,
                    queueForEvents: $events,
                );
            } catch (Exception $e) {
                $exception = $e;
            }

            // Test for FAILURE: deletion stays terminal and uses the same API
            // error as a presence missing at the initial lookup.
            $this->assertInstanceOf(Exception::class, $exception);
            $this->assertSame(Exception::PRESENCE_NOT_FOUND, $exception->getType());
            $this->assertSame(404, $exception->getCode());
            $this->assertTrue($db->getDocument('presenceLogs', $presenceId)->isEmpty());
            $this->assertSame([], $events->getParams());
        } finally {
            foreach (\array_reverse($collections) as $collection) {
                $db->deleteCollection($collection);
            }
        }
    }
}
