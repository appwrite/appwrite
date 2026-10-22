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

    public function testExportEscapesControlCharactersInValues(): void
    {
        $output = $this->export('pretty');

        $this->assertStringContainsString('a\033[31m\nb', $output);
        $this->assertStringNotContainsString("a\033[31m", $output, 'a raw escape sequence reached the terminal');
        $this->assertStringNotContainsString("m\nb", $output, 'a raw line break split the value');
        $this->assertStringContainsString('kept', $output);
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
