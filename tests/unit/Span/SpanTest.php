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
        $exporter = new class () implements Exporter {
            /**
             * @var array<Span>
             */
            public array $exported = [];

            public function export(Span $span): void
            {
                $this->exported[] = $span;
            }
        };

        Span::addExporter($exporter);

        $error = new RuntimeException('Worker failed');
        $span = new Span('worker.error');
        $span->set('appwrite.error.publish', true);
        $span->setError($error);
        $span->finish();

        $this->assertCount(1, $exporter->exported);
        $this->assertSame('worker.error', $exporter->exported[0]->getAction());
        $this->assertSame($error, $exporter->exported[0]->getError());
    }
}
