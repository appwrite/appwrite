<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Exception;
use Appwrite\GraphQL\Formatter;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use PHPUnit\Framework\TestCase;
use Utopia\Http\Http;

final class ExceptionTest extends TestCase
{
    private string $mode;

    protected function setUp(): void
    {
        $this->mode = Http::getMode();
    }

    protected function tearDown(): void
    {
        Http::setMode($this->mode);
    }

    public function testKeepsResolverDiagnosticsInDevelopment(): void
    {
        Http::setMode(Http::MODE_TYPE_DEVELOPMENT);

        $exception = Exception::fromResponse([
            'message' => 'Server Error',
            'file' => '/app/source.php',
            'line' => 42,
            'trace' => [['file' => '/app/caller.php', 'line' => 21]],
        ], 500);
        $error = new Error('Server Error', previous: $exception);
        $formatted = Formatter::errors(
            [$error],
            FormattedError::prepareFormatter(null, DebugFlag::INCLUDE_TRACE),
        );

        $this->assertSame('/app/source.php', $formatted[0]['extensions']['file']);
        $this->assertSame(42, $formatted[0]['extensions']['line']);
        $this->assertSame(
            [['file' => '/app/caller.php', 'line' => 21]],
            $formatted[0]['extensions']['trace'],
        );
    }

    public function testOmitsResolverDiagnosticsInProduction(): void
    {
        Http::setMode(Http::MODE_TYPE_PRODUCTION);

        $exception = Exception::fromResponse([
            'message' => 'Server Error',
            'file' => '/app/source.php',
            'line' => 42,
            'trace' => [['file' => '/app/caller.php', 'line' => 21]],
        ], 500);
        $error = new Error('Server Error', previous: $exception);
        $formatted = Formatter::errors(
            [$error],
            FormattedError::prepareFormatter(null, DebugFlag::NONE),
        );

        $this->assertSame('Server Error', $formatted[0]['message']);
        $this->assertArrayNotHasKey('extensions', $formatted[0]);
    }
}
