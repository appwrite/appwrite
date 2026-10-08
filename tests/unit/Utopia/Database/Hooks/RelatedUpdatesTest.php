<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\RelatedUpdates;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\Deleted;
use Utopia\Database\Event\Document\Updated;

final class RelatedUpdatesTest extends TestCase
{
    public function testItRecordsTheUpdatedDocumentsUntilStopped(): void
    {
        $recorder = new RelatedUpdates();
        $first = new Document(['$id' => 'album1', '$collection' => 'albums']);
        $second = new Document(['$id' => 'album2', '$collection' => 'albums']);

        $recorder->handle(new Updated('albums', $first));
        $recorder->handle(new Deleted('albums', $second));
        $recorder->handle(new Updated('albums', $second));

        $this->assertSame([$first, $second], $recorder->stop());

        $recorder->handle(new Updated('albums', $first));

        $this->assertSame([$first, $second], $recorder->stop());
    }

    public function testItSelectsOnlyDocumentUpdates(): void
    {
        $recorder = new RelatedUpdates();

        $this->assertTrue($recorder->handles(Event::DocumentUpdate));
        $this->assertFalse($recorder->handles(Event::DocumentDelete));
        $this->assertFalse($recorder->handles(Event::DocumentCreate));
    }
}
