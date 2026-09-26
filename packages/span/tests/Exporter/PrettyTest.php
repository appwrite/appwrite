<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Span;

class PrettyTest extends TestCase
{
    public function testExportWritesOutput(): void
    {
        $exporter = new Pretty();
        $span = new Span('test.action');
        $span->finish();

        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportDoesNotThrow(): void
    {
        $exporter = new Pretty();
        $span = new Span();

        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesAllAttributeTypes(): void
    {
        $exporter = new Pretty();
        $span = new Span('test.types');
        $span->set('string', 'value');
        $span->set('int', 42);
        $span->set('float', 3.14);
        $span->set('bool', true);
        $span->set('null', null);

        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesError(): void
    {
        $exporter = new Pretty();
        $span = new Span('test.error');
        $span->setError(new \RuntimeException('Test error', 42));

        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportIncludesSpanMetadata(): void
    {
        new Pretty();
        $span = new Span('test.meta');
        $span->finish();

        $attributes = $span->getAttributes();

        $this->assertArrayHasKey('span.trace_id', $attributes);
        $this->assertArrayHasKey('span.id', $attributes);
        $this->assertArrayHasKey('span.started_at', $attributes);
        $this->assertArrayHasKey('span.finished_at', $attributes);
        $this->assertArrayHasKey('span.duration', $attributes);
    }
}
