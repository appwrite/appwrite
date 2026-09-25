<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Operations;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;

final class OperationsTest extends TestCase
{
    public function testReadsCountRecordedDocumentsAndOneForAnyOther(): void
    {
        $operations = new Operations();
        $album = new Document(['$id' => 'album']);
        $operations->record($album, 7);

        $this->assertSame(7, $operations->reads([$album]));
        $this->assertSame(9, $operations->reads([$album, new Document(['$id' => 'cached']), new Document(['$id' => 'staged'])]));
        $this->assertSame(0, $operations->reads([]));
    }

    public function testRecordingADocumentAgainReplacesItsCount(): void
    {
        $operations = new Operations();
        $album = new Document(['$id' => 'album']);
        $operations->record($album, 7);
        $operations->record($album, 3);

        $this->assertSame(3, $operations->reads([$album]));
    }

    public function testRecordsBelongToTheDocumentInstance(): void
    {
        $operations = new Operations();
        $cursor = new Document(['$id' => 'album']);
        $operations->record($cursor, 7);

        $this->assertSame(1, $operations->reads([new Document(['$id' => 'album'])]), 'the same document read again is another read');
    }

    public function testCountsListTheOperationsOfEachDocumentInOrder(): void
    {
        $operations = new Operations();
        $album = new Document(['$id' => 'album']);
        $operations->record($album, 7);

        $this->assertSame([1, 7, 1], $operations->counts([new Document(['$id' => 'single']), $album, new Document(['$id' => 'cached'])]));
        $this->assertSame([7], $operations->counts(['album' => $album]), 'the counts are a list, whatever the documents are keyed by');
        $this->assertSame([], $operations->counts([]));
    }

    public function testWritesCountEveryRelatedDocumentNestedInThePayload(): void
    {
        $payload = [
            'name' => 'Album',
            'artist' => ['name' => 'Artist'],
            'tracks' => [
                new Document(['$id' => 'track1', 'genres' => [['$id' => 'rock'], 'jazz']]),
                ['$id' => 'track2', 'genres' => []],
                'track3',
            ],
        ];

        $this->assertSame(5, Operations::writes($this->albums(), [$payload], $this->collections()), 'the album, artist, track1, rock and track2');
    }

    public function testWritesDoNotCountLinksOrEmptyRelationships(): void
    {
        $collections = $this->collections();

        $this->assertSame(1, Operations::writes($this->albums(), [['artist' => 'artist1', 'tracks' => ['track1', 'track2']]], $collections));
        $this->assertSame(1, Operations::writes($this->albums(), [['artist' => null, 'tracks' => []]], $collections));
        $this->assertSame(1, Operations::writes($this->albums(), [new Document(['name' => 'Album'])], $collections));
    }

    public function testWritesCountEveryDocumentOfABatch(): void
    {
        $documents = [new Document(['$id' => 'rock']), new Document(['$id' => 'jazz']), ['$id' => 'soul']];

        $this->assertSame(3, Operations::writes($this->genres(), $documents, $this->collections()));
    }

    public function testWritesOnlyCountDocumentsUnderRelationships(): void
    {
        $payload = [
            'name' => ['$id' => 'not-a-relationship'],
            'tracks' => [['$id' => 'track1', 'name' => ['$id' => 'not-a-relationship-either']]],
        ];

        $this->assertSame(2, Operations::writes($this->albums(), [$payload], $this->collections()));
    }

    public function testWritesLookUpACollectionOnlyForDocumentsNestedInNestedDocuments(): void
    {
        $lookups = [];
        $collections = function (string $id) use (&$lookups): Document {
            $lookups[] = $id;

            return ($this->collections())($id);
        };

        Operations::writes($this->albums(), [['tracks' => [['$id' => 'track1'], ['$id' => 'track2', 'name' => 'Track']]]], $collections);
        $this->assertSame([], $lookups, 'a related document without documents of its own needs no schema');

        Operations::writes($this->albums(), [['tracks' => [
            ['$id' => 'track1', 'genres' => [['$id' => 'rock']]],
            ['$id' => 'track2', 'genres' => [['$id' => 'jazz']]],
        ]]], $collections);
        $this->assertSame(['tracks'], $lookups, 'each related collection is looked up once per payload');
    }

    private function albums(): Document
    {
        return self::collection('albums', [
            self::attribute('name', ColumnType::String),
            self::relationship('artist', 'artists'),
            self::relationship('tracks', 'tracks'),
        ]);
    }

    private function genres(): Document
    {
        return self::collection('genres', [self::attribute('name', ColumnType::String)]);
    }

    /**
     * @return callable(string): Document
     */
    private function collections(): callable
    {
        $collections = [
            'artists' => self::collection('artists', [self::attribute('name', ColumnType::String)]),
            'tracks' => self::collection('tracks', [
                self::attribute('name', ColumnType::String),
                self::relationship('album', 'albums'),
                self::relationship('genres', 'genres'),
            ]),
            'genres' => $this->genres(),
        ];

        return static fn (string $id): Document => $collections[$id] ?? new Document();
    }

    /**
     * @param list<Document> $attributes
     */
    private static function collection(string $id, array $attributes): Document
    {
        return new Document(['$id' => $id, 'attributes' => $attributes]);
    }

    private static function attribute(string $key, ColumnType $type): Document
    {
        return new Document(['$id' => $key, 'key' => $key, 'type' => $type->value]);
    }

    private static function relationship(string $key, string $relatedCollection): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'relatedCollection' => $relatedCollection,
        ]);
    }
}
