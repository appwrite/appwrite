<?php

declare(strict_types=1);

namespace Tests\Unit\Span;

use Appwrite\Span\Factory;
use PHPUnit\Framework\TestCase;
use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Exporter\Stdout;

final class FactoryTest extends TestCase
{
    private bool $hadFormat = false;

    private string $previousFormat = '';

    protected function setUp(): void
    {
        $previous = \getenv('_APP_LOGGING_FORMAT');
        $this->hadFormat = $previous !== false;
        $this->previousFormat = $previous === false ? '' : $previous;

        \putenv('_APP_LOGGING_FORMAT');
        unset($_ENV['_APP_LOGGING_FORMAT'], $_SERVER['_APP_LOGGING_FORMAT']);
    }

    protected function tearDown(): void
    {
        if (!$this->hadFormat) {
            \putenv('_APP_LOGGING_FORMAT');
            unset($_ENV['_APP_LOGGING_FORMAT'], $_SERVER['_APP_LOGGING_FORMAT']);
            return;
        }

        \putenv('_APP_LOGGING_FORMAT=' . $this->previousFormat);
        $_ENV['_APP_LOGGING_FORMAT'] = $this->previousFormat;
        $_SERVER['_APP_LOGGING_FORMAT'] = $this->previousFormat;
    }

    public function testUnsetEnvDefaultsToPrettyExporter(): void
    {
        $exporter = Factory::createExporter();

        $this->assertInstanceOf(Pretty::class, $exporter);
    }

    public function testPrettyFormatUsesPrettyExporter(): void
    {
        $exporter = Factory::createExporter(format: Factory::FORMAT_PRETTY);

        $this->assertInstanceOf(Pretty::class, $exporter);
    }

    public function testJsonFormatUsesStdoutExporter(): void
    {
        $exporter = Factory::createExporter(format: Factory::FORMAT_JSON);

        $this->assertInstanceOf(Stdout::class, $exporter);
    }

    public function testJsonFormatIsCaseInsensitive(): void
    {
        $exporter = Factory::createExporter(format: 'JSON');

        $this->assertInstanceOf(Stdout::class, $exporter);
    }

    public function testUnknownFormatFallsBackToPretty(): void
    {
        $exporter = Factory::createExporter(format: 'yaml');

        $this->assertInstanceOf(Pretty::class, $exporter);
    }

    public function testEnvJsonSelectsStdoutExporter(): void
    {
        \putenv('_APP_LOGGING_FORMAT=json');
        $_ENV['_APP_LOGGING_FORMAT'] = 'json';
        $_SERVER['_APP_LOGGING_FORMAT'] = 'json';

        $exporter = Factory::createExporter();

        $this->assertInstanceOf(Stdout::class, $exporter);
    }
}
