<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

/** Keeps every finished span, in order, for a test to read back. */
final class RecordingExporter implements Exporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(Span $span): void
    {
        $this->spans[] = $span;
    }

    public function sample(Span $span): bool
    {
        return true;
    }

    /**
     * The finished spans of one action.
     *
     * @return list<Span>
     */
    public function of(string $action): array
    {
        return \array_values(\array_filter($this->spans, fn (Span $span): bool => $span->getAction() === $action));
    }
}
