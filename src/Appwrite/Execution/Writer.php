<?php

namespace Appwrite\Execution;

use Appwrite\Execution\Writer\Entry;
use Appwrite\Execution\Writer\Signal;
use Closure;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;
use Utopia\Span\Span;

final class Writer
{
    /** @var list<Entry> */
    private array $pending = [];

    private ?Entry $leader = null;

    /** @param Closure(list<array<string, mixed>>): void $flush */
    public function __construct(private readonly Closure $flush)
    {
    }

    /** @param list<array<string, mixed>> $rows */
    public function write(array $rows): void
    {
        if (Coroutine::getCid() < 0) {
            ($this->flush)($rows);
            return;
        }

        $entry = new Entry($rows);
        $this->pending[] = $entry;

        if ($this->leader === null) {
            $this->leader = $entry;
            $this->lead($entry);
            return;
        }

        $this->follow($entry);
    }

    private function follow(Entry $entry): void
    {
        try {
            $signal = $entry->channel->pop();
        } catch (Throwable $error) {
            $this->abandon($entry);
            throw $error;
        }

        if ($signal === Signal::Lead) {
            $this->lead($entry);
            return;
        }
        if ($signal === Signal::Done) {
            if ($entry->error !== null) {
                throw $entry->error;
            }
            return;
        }

        $this->abandon($entry);
        throw new RuntimeException('Execution write interrupted while waiting for its batch; its rows may have been written');
    }

    private function lead(Entry $entry): void
    {
        $batch = $this->pending;
        $this->pending = [];

        $rows = [];
        foreach ($batch as $member) {
            \array_push($rows, ...$member->rows);
        }
        try {
            Span::add('executions.batch', \count($rows));
            ($this->flush)($rows);
        } catch (Throwable $error) {
            foreach ($batch as $member) {
                $member->error = $member === $entry
                    ? $error
                    : new RuntimeException('Execution batch insert failed: ' . $error->getMessage(), previous: $error);
            }
        } finally {
            foreach ($batch as $member) {
                if ($member !== $entry) {
                    $member->channel->push(Signal::Done, 0);
                }
            }
            $this->handoff();
        }

        if ($entry->error !== null) {
            throw $entry->error;
        }
    }

    private function handoff(): void
    {
        $next = $this->pending[0] ?? null;
        $this->leader = $next;

        // Pushing Lead runs the new leader inline, so nothing may touch Writer state after it.
        $next?->channel->push(Signal::Lead, 0);
    }

    private function abandon(Entry $entry): void
    {
        $this->pending = \array_values(\array_filter(
            $this->pending,
            fn (Entry $pending): bool => $pending !== $entry,
        ));
    }
}
