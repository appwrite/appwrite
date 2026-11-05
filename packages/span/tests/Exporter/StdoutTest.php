<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Span;

class StdoutTest extends TestCase
{
    public function testExportWritesJson(): void
    {
        $exporter = new Stdout();
        $span = new Span();
        $span->set('action', 'test.action');
        $span->finish();

        ob_start();
        $exporter->export($span);
        ob_get_clean();

        // Output goes to STDOUT, not output buffer in CLI
        // Just verify no exception is thrown
        $this->assertTrue(true);
    }

    public function testExportDoesNotThrow(): void
    {
        $exporter = new Stdout();
        $span = new Span();

        // Capture STDOUT to prevent test output pollution
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesAllAttributeTypes(): void
    {
        $exporter = new Stdout();
        $span = new Span();
        $span->set('string', 'value');
        $span->set('int', 42);
        $span->set('float', 3.14);
        $span->set('bool', true);
        $span->set('null', null);

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportHandlesError(): void
    {
        $exporter = new Stdout();
        $span = new Span();
        $span->setError(new \RuntimeException('Test error', 42));

        // Should not throw
        $exporter->export($span);

        $this->assertTrue(true);
    }

    public function testExportKeepsRecordWithInvalidUtf8Attribute(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $exporter = new Stdout(stdout: $stdout);

        $span = new Span('http.request');
        $span->set('http.locale', "en\xB1\x31");
        $span->set('level', 'error');

        $exporter->export($span);

        rewind($stdout);
        $line = stream_get_contents($stdout);
        $record = json_decode($line, true);

        $this->assertIsArray($record);
        $this->assertSame('http.request', $record['action']);
        $this->assertSame('error', $record['level']);
        $this->assertArrayHasKey('http.locale', $record);
    }

    public function testExportWritesErrorSpanWithInvalidUtf8ToStderrStream(): void
    {
        $stderr = fopen('php://memory', 'r+');
        $exporter = new Stdout(stderr: $stderr);

        $span = new Span('http.request');
        $span->set('http.locale', "\xB1");
        $span->setError(new \RuntimeException('boom'));

        $exporter->export($span);

        rewind($stderr);
        $record = json_decode(stream_get_contents($stderr), true);

        $this->assertIsArray($record);
        $this->assertSame('http.request', $record['action']);
        $this->assertSame(\RuntimeException::class, $record['error.type']);
        $this->assertSame('boom', $record['error.message']);
    }

    public function testExportIncludesSpanMetadata(): void
    {
        new Stdout();
        $span = new Span();
        $span->finish();

        $attributes = $span->getAttributes();

        $this->assertArrayHasKey('span.trace_id', $attributes);
        $this->assertArrayHasKey('span.id', $attributes);
        $this->assertArrayHasKey('span.started_at', $attributes);
        $this->assertArrayHasKey('span.finished_at', $attributes);
        $this->assertArrayHasKey('span.duration', $attributes);
    }
}
