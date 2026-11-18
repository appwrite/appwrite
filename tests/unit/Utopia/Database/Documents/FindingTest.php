<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<string, mixed>, bool|null}>
     */
    public static function findings(): \Iterator
    {
        yield 'fixed by a later commit' => [['status' => 'resolved', 'settlement' => 'commit'], true];
        yield 'said fixed in its thread' => [['status' => 'resolved', 'settlement' => 'chat'], true];
        yield 'declined in its thread' => [['status' => 'dismissed', 'settlement' => 'chat'], false];
        yield 'resolved without a change' => [['status' => 'dismissed', 'settlement' => 'thread'], false];
        yield 'settled before settlements were kept' => [['status' => 'dismissed'], false];
        yield 'open without reactions' => [['status' => 'posted'], null];
        yield 'open and liked' => [['status' => 'posted', 'upvotes' => 1], true];
        yield 'open and disliked' => [['status' => 'posted', 'downvotes' => 2, 'upvotes' => 1], false];
        yield 'dismissed but liked' => [['status' => 'dismissed', 'upvotes' => 2], true];
        yield 'fixed but disliked' => [['status' => 'resolved', 'downvotes' => 1], false];
        yield 'fixed with reactions that cancel out' => [['status' => 'resolved', 'upvotes' => 1, 'downvotes' => 1], true];
        yield 'open with reactions that cancel out' => [['status' => 'posted', 'upvotes' => 1, 'downvotes' => 1], null];
        yield 'dropped, even if liked' => [['status' => 'dropped', 'upvotes' => 3], null];
        yield 'without a status' => [[], null];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('findings')]
    public function testIsUseful(array $attributes, ?bool $useful): void
    {
        $this->assertSame($useful, (new Finding($attributes))->isUseful());
    }
}
