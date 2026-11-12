<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * A Database that lets another request's write land right after one chosen document create,
 * the way a concurrent linker's insert commits between this request's own write and the read
 * it makes to check what it wrote.
 */
final class RacingDatabase extends Database
{
    private string $collection = '';

    private int $call = 0;

    private ?\Closure $callback = null;

    /** @var array<string, int> collection => creates so far */
    private array $creates = [];

    public function interleave(string $collection, int $call, \Closure $callback): void
    {
        $this->collection = $collection;
        $this->call = $call;
        $this->callback = $callback;
    }

    #[\Override]
    public function createDocument(string $collection, Document $document): Document
    {
        $created = parent::createDocument($collection, $document);

        $this->creates[$collection] = ($this->creates[$collection] ?? 0) + 1;

        if ($this->callback !== null && $collection === $this->collection && $this->creates[$collection] === $this->call) {
            ($this->callback)();
        }

        return $created;
    }
}
