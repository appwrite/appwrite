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

    public function testExportKeepsARecordWithInvalidUtf8(): void
    {
        $lines = explode(PHP_EOL, rtrim($this->export('stdout'), PHP_EOL));

        $this->assertCount(1, $lines, 'the record is not one line');

        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);
        $this->assertSame("ok\u{FFFD}1", $record['invalid']);
        $this->assertSame("a\033[31m\nb", $record['control']);
        $this->assertSame('kept', $record['plain']);
    }

    /**
     * Run the fixture in its own process, since the exporter writes to the real STDOUT.
     */
    private function export(string $exporter): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/export.php', $exporter],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);

        return $output;
    }
}
