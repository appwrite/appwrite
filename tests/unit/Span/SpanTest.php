<?php

namespace Tests\Unit\Span;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

class SpanTest extends TestCase
{
    protected function tearDown(): void
    {
        Span::reset();
    }

    public function testStandaloneSpanExportsOnFinish(): void
    {
        $exported = [];

        Span::addExporter(new class ($exported) implements Exporter {
            public function __construct(private array &$exported)
            {
            }

            public function export(Span $span): void
            {
                $this->exported[] = $span;
            }
        });

        $error = new RuntimeException('Worker failed');
        $span = new Span('worker.error');
        $span->set('appwrite.error.publish', true);
        $span->setError($error);
        $span->finish();

        $this->assertCount(1, $exported);
        $this->assertSame('worker.error', $exported[0]->getAction());
        $this->assertSame($error, $exported[0]->getError());
    }
}
