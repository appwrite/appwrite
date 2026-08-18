<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Payload;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class PayloadTest extends TestCase
{
    public function testDocumentAcceptsArrayAndDocument(): void
    {
        $fromArray = Payload::document(['$id' => 'proj', 'name' => 'Demo']);
        $this->assertSame('proj', $fromArray->getId());
        $this->assertSame('Demo', $fromArray->getAttribute('name'));

        $existing = new Document(['$id' => 'live']);
        $this->assertSame($existing, Payload::document($existing));

        $this->assertTrue(Payload::document(null)->isEmpty());
    }

    public function testDocumentOrNull(): void
    {
        $this->assertNotInstanceOf(Document::class, Payload::documentOrNull(null));
        $this->assertNotInstanceOf(Document::class, Payload::documentOrNull([]));

        $existing = new Document(['$id' => 'live']);
        $this->assertSame($existing, Payload::documentOrNull($existing));

        $fromArray = Payload::documentOrNull(['$id' => 'proj']);
        $this->assertInstanceOf(Document::class, $fromArray);
        $this->assertSame('proj', $fromArray->getId());
    }

    public function testJsonArrayNormalizesEmptyObjects(): void
    {
        $normalized = Payload::jsonArray(['prefs' => new \stdClass(), 'name' => 'Ada']);
        $this->assertSame([], $normalized['prefs']);
        $this->assertSame('Ada', $normalized['name']);
        $this->assertSame([], Payload::jsonArray(null));
    }
}
