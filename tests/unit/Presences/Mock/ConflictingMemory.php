<?php

declare(strict_types=1);

namespace Tests\Unit\Presences\Mock;

use Utopia\Database\Adapter\Memory;
use Utopia\Database\Exception\Transaction as TransactionException;

/**
 * Memory database whose next outermost commit fails with a retryable error,
 * optionally letting another request commit a write before the retry.
 */
final class ConflictingMemory extends Memory
{
    private bool $conflict = false;

    private ?\Closure $competingWrite = null;

    /**
     * @param (\Closure(): mixed)|null $competingWrite
     */
    public function conflictOnNextCommit(?\Closure $competingWrite = null): void
    {
        $this->conflict = true;
        $this->competingWrite = $competingWrite;
    }

    #[\Override]
    public function commitTransaction(): bool
    {
        if ($this->conflict && $this->inTransaction === 1) {
            $this->conflict = false;
            throw new TransactionException('Deadlock found when trying to get lock; try restarting transaction');
        }

        return parent::commitTransaction();
    }

    #[\Override]
    public function rollbackTransaction(): bool
    {
        $rolledBack = parent::rollbackTransaction();

        if ($this->competingWrite !== null && $this->inTransaction === 0) {
            $competingWrite = $this->competingWrite;
            $this->competingWrite = null;
            $competingWrite();
        }

        return $rolledBack;
    }
}
