<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Hooks\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Event;

final class UsageTest extends TestCase
{
    public static function formerlyReducedCollections(): \Iterator
    {
        yield 'users' => ['users'];
        yield 'databases' => ['databases'];
        yield 'collections' => ['database_3'];
        yield 'buckets' => ['buckets'];
        yield 'functions' => ['functions'];
        yield 'sites' => ['sites'];
    }

    #[DataProvider('formerlyReducedCollections')]
    public function testDeletingAResourceAddsNothingToReduce(string $collection): void
    {
        $context = new Context();

        (new Usage($context))->handle(Event::DocumentDelete, new Document([
            '$id' => 'resource1',
            '$collection' => $collection,
            'prefs' => ['theme' => 'dark'],
        ]));

        $this->assertSame([], $context->getReduce());
    }
}
