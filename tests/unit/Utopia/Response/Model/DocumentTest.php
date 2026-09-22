<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Document as DocumentModel;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DocumentTest extends TestCase
{
    public function testFilterRemovesInternalAttributes(): void
    {
        $document = new Document([
            '$id' => 'doc1',
            '$collection' => 'movies',
            '$tenant' => 1,
            '$sequence' => 9,
            'title' => 'Captain America',
        ]);

        $filtered = (new DocumentModel())->filter($document);

        $this->assertFalse($filtered->isSet('$collection'));
        $this->assertFalse($filtered->isSet('$tenant'));
        $this->assertSame('Captain America', $filtered->getAttribute('title'));
    }
}
