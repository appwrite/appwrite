<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

/** Keeps every finished span so a test can read it. */
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
}
