<?php

declare(strict_types=1);

namespace Utopia\Span\Exporter;

use Closure;
use Utopia\Span\Span;

/**
 * Exports spans as newline-delimited JSON (NDJSON).
 *
 * Writes error spans to stderr and non-error spans to stdout.
 * Error stacktraces are truncated to keep output readable.
 */
readonly class Stdout implements Exporter
{
    /** @var Closure(Span): bool */
    private Closure $sampler;

    /**
     * Create a new Stdout exporter.
     *
     * @param Closure(Span): bool|null $sampler Filter function. Defaults to exporting every span.
     * @param int $maxTraceFrames Maximum stacktrace frames to include for errors
     */
    public function __construct(
        ?Closure $sampler = null,
        private int $maxTraceFrames = 3,
    ) {
        $this->sampler = $sampler ?? static fn(Span $span): bool => true;
    }

    public function sample(Span $span): bool
    {
        return ($this->sampler)($span);
    }

    public function export(Span $span): void
    {
        $attributes = $span->getAttributes();
        $ordered = [];
        if (\array_key_exists('level', $attributes)) {
            $ordered['level'] = $attributes['level'];
            unset($attributes['level']);
        }
        $ordered['action'] = $span->getAction();

        $data = $ordered + $attributes;
        $error = $span->getError();

        if ($error instanceof \Throwable) {
            $data['error.type'] = $error::class;
            $data['error.message'] = $error->getMessage();
            $data['error.code'] = $error->getCode();
            $data['error.file'] = $error->getFile();
            $data['error.line'] = $error->getLine();

            $trace = $error->getTrace();
            $limited = \array_slice($trace, 0, $this->maxTraceFrames);
            $data['error.trace'] = array_map(fn(array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'],
            ], $limited);

            if (\count($trace) > $this->maxTraceFrames) {
                $data['error.trace_truncated'] = \count($trace) - $this->maxTraceFrames;
            }
        }

        $output = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($output === false) {
            return;
        }

        $stream = $error instanceof \Throwable ? STDERR : STDOUT;

        fwrite($stream, $output . PHP_EOL);
    }
}
