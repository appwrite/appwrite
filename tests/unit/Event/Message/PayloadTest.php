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
        $this->assertNull(Payload::documentOrNull(null));
        $this->assertNull(Payload::documentOrNull([]));

        $existing = new Document(['$id' => 'live']);
        $this->assertSame($existing, Payload::documentOrNull($existing));

        $fromArray = Payload::documentOrNull(['$id' => 'proj']);
        $this->assertInstanceOf(Document::class, $fromArray);
        $this->assertSame('proj', $fromArray->getId());
    }
}
