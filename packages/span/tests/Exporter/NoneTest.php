<?php

namespace Utopia\Span\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Exporter\None;
use Utopia\Span\Span;

class NoneTest extends TestCase
{
    public function testExportDoesNotThrow(): void
    {
        $exporter = new None();
        $span = new Span();

        // Should not throw any exception
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportAcceptsAnySpan(): void
    {
        $exporter = new None();

        $span1 = new Span();
        $span1->set('action', 'test');

        $span2 = new Span();
        $span2->setError(new \RuntimeException('Error'));

        // Should accept any span without error
        $exporter->export($span1);
        $exporter->export($span2);

        $this->assertTrue(true);
    }

    public function testMultipleExportsDoNotAccumulate(): void
    {
        $exporter = new None();

        for ($i = 0; $i < 100; $i++) {
            $span = new Span();
            $exporter->export($span);
        }

        // None exporter should not store anything
        $this->assertTrue(true);
    }
}
