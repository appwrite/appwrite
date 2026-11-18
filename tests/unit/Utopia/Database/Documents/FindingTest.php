<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, ?bool}>
     */
    public static function findings(): array
    {
        return [
            'fixed by a later commit' => [['status' => 'resolved', 'settlement' => 'commit'], true],
            'said fixed in its thread' => [['status' => 'resolved', 'settlement' => 'chat'], true],
            'declined in its thread' => [['status' => 'dismissed', 'settlement' => 'chat'], false],
            'resolved without a change' => [['status' => 'dismissed', 'settlement' => 'thread'], false],
            'settled before settlements were kept' => [['status' => 'dismissed'], false],
            'open without reactions' => [['status' => 'posted'], null],
            'open and liked' => [['status' => 'posted', 'upvotes' => 1], true],
            'open and disliked' => [['status' => 'posted', 'downvotes' => 2, 'upvotes' => 1], false],
            'dismissed but liked' => [['status' => 'dismissed', 'upvotes' => 2], true],
            'fixed but disliked' => [['status' => 'resolved', 'downvotes' => 1], false],
            'fixed with reactions that cancel out' => [['status' => 'resolved', 'upvotes' => 1, 'downvotes' => 1], true],
            'open with reactions that cancel out' => [['status' => 'posted', 'upvotes' => 1, 'downvotes' => 1], null],
            'dropped, even if liked' => [['status' => 'dropped', 'upvotes' => 3], null],
            'without a status' => [[], null],
        ];
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
