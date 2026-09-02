<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Database\Database;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../../../../app/init.php';

final class CreateTest extends TestCase
{
    /**
     * The bucket document and its files collection are two writes that cannot
     * share a transaction: the collection is DDL, and its name needs the
     * document's sequence. Production accumulated bucket documents whose
     * collection creation had failed (a lock wait, a killed pod); every later
     * request against such a bucket fails with "Collection not found". The
     * property that matters is what the buckets collection holds once the
     * request has failed, not the wording of the failure.
     */
    public function testDocumentIsRemovedWhenTheCollectionCannotBeCreated(): void
    {
        $buckets = [];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('createDocument')->willReturnCallback(
            static function (string $collection, Document $document) use (&$buckets): Document {
                $buckets[$document->getId()] = $document->setAttribute('$sequence', '7');

                return $buckets[$document->getId()];
            }
        );
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $buckets[$id] ?? new Document()
        );
        $dbForProject->method('deleteDocument')->willReturnCallback(
            static function (string $collection, string $id) use (&$buckets): bool {
                unset($buckets[$id]);

                return true;
            }
        );
        $dbForProject->method('createCollection')->willThrowException(
            new RuntimeException('Lock wait timeout exceeded')
        );

        $response = $this->createStub(Response::class);
        $queueForEvents = $this->createStub(Event::class);

        try {
            (new Create())->action(
                'photos',
                'Photos',
                null,
                false,
                true,
                30000000,
                [],
                null,
                null,
                true,
                true,
                $response,
                $dbForProject,
                $queueForEvents,
            );
            $this->fail('A failed collection creation must fail the request.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $buckets, 'A bucket whose files collection was never created must not survive the request.');
    }
}
