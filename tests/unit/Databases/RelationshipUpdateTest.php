<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\RelationshipUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipType;

final class RelationshipUpdateTest extends TestCase
{
    private const string KEY = 'author';
    private const string NEW_KEY = 'writer';

    private const array AUTHOR = [
        'relatedCollection' => 'authors',
        'relationType' => RelationshipType::OneToOne->value,
        'twoWay' => true,
        'twoWayKey' => 'book',
        'onDelete' => RelationshipDeleteAction::Cascade->value,
        'side' => RelationshipSide::Parent->value,
    ];

    private const array BOOK = [
        'relatedCollection' => 'books',
        'relationType' => RelationshipType::OneToOne->value,
        'twoWay' => true,
        'twoWayKey' => self::KEY,
        'onDelete' => RelationshipDeleteAction::Cascade->value,
        'side' => RelationshipSide::Child->value,
    ];

    public function testRenameWithoutOnDeleteKeepsTheStoredAction(): void
    {
        $update = new RelationshipUpdate(['onDelete' => null], self::KEY, self::NEW_KEY);

        $onDelete = $update->onDelete();
        $author = $update->options(self::AUTHOR);
        $book = $update->related(self::BOOK);

        $this->assertNull($onDelete);
        $this->assertSame(RelationshipDeleteAction::Cascade->value, $author['onDelete']);
        $this->assertSame(RelationshipDeleteAction::Cascade->value, $book['onDelete']);
        $this->assertSame(self::NEW_KEY, $book['twoWayKey']);
    }

    public function testEmptyUpdateLeavesTheRelationshipUnchanged(): void
    {
        $update = new RelationshipUpdate(['onDelete' => null], self::KEY);

        $onDelete = $update->onDelete();
        $author = $update->options(self::AUTHOR);
        $book = $update->related(self::BOOK);

        $this->assertNull($onDelete);
        $this->assertSame(self::AUTHOR, $author);
        $this->assertSame(self::BOOK, $book);
    }

    public function testOnDeleteIsAppliedToBothSides(): void
    {
        $update = new RelationshipUpdate(['onDelete' => RelationshipDeleteAction::SetNull->value], self::KEY);

        $onDelete = $update->onDelete();
        $author = $update->options(self::AUTHOR);
        $book = $update->related(self::BOOK);

        $this->assertSame(RelationshipDeleteAction::SetNull, $onDelete);
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $author['onDelete']);
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $book['onDelete']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function keysThatRenameNothing(): \Iterator
    {
        yield 'same key' => [self::KEY];
        yield 'empty key' => [''];
    }

    #[DataProvider('keysThatRenameNothing')]
    public function testNewKeyThatRenamesNothingLeavesTheRelatedSideUnchanged(string $newKey): void
    {
        $update = new RelationshipUpdate(['onDelete' => null], self::KEY, $newKey);

        $book = $update->related(self::BOOK);

        $this->assertSame(self::BOOK, $book);
    }
}
