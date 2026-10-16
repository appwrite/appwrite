<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Database\Documents\Aggregate;
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

    public function testFilterKeepsTheStoredAttributesOfAStoredRecord(): void
    {
        $document = new Document([
            '$id' => 'doc1',
            '$sequence' => 9,
            '$collectionId' => 'movies',
            '$databaseId' => 'db',
            '$createdAt' => '2023-06-01T00:00:00.000+00:00',
            '$updatedAt' => '2023-06-02T00:00:00.000+00:00',
            '$permissions' => ['read("any")'],
            'title' => 'Captain America',
        ]);

        $filtered = (new DocumentModel())->filter($document);

        $this->assertSame([
            '$id' => 'doc1',
            '$sequence' => '9',
            '$collectionId' => 'movies',
            '$databaseId' => 'db',
            '$createdAt' => '2023-06-01T00:00:00.000+00:00',
            '$updatedAt' => '2023-06-02T00:00:00.000+00:00',
            '$permissions' => ['read("any")'],
            'title' => 'Captain America',
        ], $filtered->getArrayCopy());
    }

    public function testFilterAddsNoStoredAttributesToAnAggregatedRecord(): void
    {
        $filtered = (new DocumentModel())->filter(new Aggregate(['genre' => 'drama', 'total' => 7]));

        $this->assertSame(['genre' => 'drama', 'total' => 7], $filtered->getArrayCopy());
    }

    public function testFilterKeepsTheStoredAttributesAnAggregatedRecordGroups(): void
    {
        $filtered = (new DocumentModel())->filter(new Aggregate([
            'total' => 1,
            '$id' => 'doc1',
            '$sequence' => '9',
            '$createdAt' => '2023-06-01T00:00:00.000+00:00',
            '$permissions' => ['read("any")'],
        ]));

        $this->assertSame([
            'total' => 1,
            '$id' => 'doc1',
            '$sequence' => '9',
            '$createdAt' => '2023-06-01T00:00:00.000+00:00',
            '$permissions' => ['read("any")'],
        ], $filtered->getArrayCopy());
    }

    public function testFilterRemovesTheEmptyStoredAttributesOfADistinctRecord(): void
    {
        $filtered = (new DocumentModel())->filter((new Document([
            'genre' => 'drama',
            '$sequence' => null,
            '$collection' => 'movies',
            '$createdAt' => null,
            '$updatedAt' => null,
            '$databaseId' => 'db',
            '$collectionId' => 'movies',
        ]))->setAttribute('$id', null));

        $this->assertSame(['genre' => 'drama'], $filtered->getArrayCopy());
    }

    public function testFilterRemovesTheStoredAttributesOfAnUnmatchedJoinRecord(): void
    {
        $filtered = (new DocumentModel())->filter(new Document([
            'name' => null,
            'ord.amount' => 25,
            'ord.$id' => 'o4',
            '$id' => '',
            '$sequence' => null,
            '$createdAt' => null,
            '$updatedAt' => null,
            '$permissions' => [],
            '$databaseId' => 'db',
            '$collectionId' => 'customers',
        ]));

        $this->assertSame(['name' => null, 'ord.amount' => 25, 'ord.$id' => 'o4'], $filtered->getArrayCopy());
    }

    public function testFilterFillsTheStoredAttributesAStagedRecordLacks(): void
    {
        $filtered = (new DocumentModel())->filter(new Document(['$id' => 'doc1', 'title' => 'Captain America']));

        $this->assertSame([
            '$id' => 'doc1',
            'title' => 'Captain America',
            '$sequence' => '',
            '$createdAt' => '',
            '$updatedAt' => '',
        ], $filtered->getArrayCopy());
    }
}
