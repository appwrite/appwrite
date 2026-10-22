<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;

/**
 * Stack traces reach logs, spans, error reports, stored migration errors and
 * development error responses. The bootstrap keeps call arguments out of
 * them, so a password, key or connection secret passed down the stack is
 * never captured with the exception.
 */
final class TracesTest extends TestCase
{
    public function testExceptionsCarryNoArguments(): void
    {
        $error = $this->capture('hunter2-secret');

        $frames = \array_filter($error->getTrace(), fn (array $frame): bool => ($frame['class'] ?? '') === self::class);

        $this->assertNotEmpty($frames);
        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
        }
        $this->assertStringNotContainsString('hunter2', $error->getTraceAsString());
    }

    private function capture(string $secret): \Throwable
    {
        try {
            $this->throw($secret);
        } catch (\Throwable $error) {
            return $error;
        }
    }

    private function throw(string $secret): never
    {
        throw new \RuntimeException('Refused');
    }
}
