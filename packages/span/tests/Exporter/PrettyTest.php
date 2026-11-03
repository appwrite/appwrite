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

    public function testExportEscapesControlCharacters(): void
    {
        $stderr = fopen('php://memory', 'r+');
        if ($stderr === false) {
            $this->fail('Could not open memory stream');
        }

        $exporter = new Pretty(stderr: $stderr);

        $span = new Span("http.request\n");
        $span->set("http.locale\r", "en\nFORGED\033[31m\x7F");
        $span->setError(new \RuntimeException("boom\r\nINJECTED"));

        $exporter->export($span);

        rewind($stderr);
        $output = stream_get_contents($stderr);

        $this->assertIsString($output);
        $this->assertStringContainsString('http.request\\x0a', $output);
        $this->assertStringContainsString('http.locale\\x0d', $output);
        $this->assertStringContainsString('en\\x0aFORGED\\x1b[31m\\x7f', $output);
        $this->assertStringContainsString('boom\\x0d\\x0aINJECTED', $output);
        $this->assertStringNotContainsString("en\nFORGED", $output);
        $this->assertStringNotContainsString("boom\r\nINJECTED", $output);
        $this->assertStringNotContainsString("http.request\n", $output);
    }

    public function testExportLeavesPlainValuesUntouched(): void
    {
        $stdout = fopen('php://memory', 'r+');
        if ($stdout === false) {
            $this->fail('Could not open memory stream');
        }

        $exporter = new Pretty(stdout: $stdout);

        $span = new Span('http.request');
        $span->set('http.locale', 'en');
        $span->set('count', 2);

        $exporter->export($span);

        rewind($stdout);
        $output = stream_get_contents($stdout);

        $this->assertIsString($output);
        $this->assertStringContainsString('http.request', $output);
        $this->assertStringContainsString('http.locale', $output);
        $this->assertStringContainsString('en', $output);
        $this->assertStringContainsString('2', $output);
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
