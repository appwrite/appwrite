<?php

declare(strict_types=1);

namespace Utopia\Span;

use Throwable;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Storage\Storage;

class Span
{
    private static ?Storage $storage = null;

    /**
     * @var array<Exporter>
     */
    private static array $exporters = [];

    /**
     * @var array<string, string|int|float|bool|null>
     */
    private array $attributes = [];

    private ?Throwable $error = null;

    public function __construct(private readonly string $action = 'unknown')
    {
        $this->attributes['span.trace_id'] = bin2hex(random_bytes(16));
        $this->attributes['span.id'] = bin2hex(random_bytes(8));
        $this->attributes['span.started_at'] = microtime(true);
    }

    /**
     * Set (or clear) the storage backend for span context.
     *
     * Call once at application startup before creating spans. Pass null to clear.
     *
     * @param Storage|null $storage Use Storage\Auto for automatic detection, or null to clear
     */
    public static function setStorage(?Storage $storage): void
    {
        self::$storage = $storage;
    }

    /**
     * Replace all exporters.
     *
     * Exporters receive finished spans. Each exporter decides whether to export
     * via its own {@see Exporter::sample()} method.
     *
     * @param Exporter ...$exporters Exporters to register, replacing any previously set
     */
    public static function setExporters(Exporter ...$exporters): void
    {
        self::$exporters = $exporters;
    }

    /**
     * Initialize a new span and set it as current.
     *
     * Creates a new span with unique trace and span IDs. If a traceparent header
     * is provided, the span will continue that trace (for distributed tracing).
     *
     * @param string $action What this span represents (e.g., 'http.request', 'db.query')
     * @param string|null $traceparent W3C traceparent header from incoming request
     * @return self The new span instance
     */
    public static function init(string $action, ?string $traceparent = null): self
    {
        $span = new self($action);

        if ($traceparent !== null) {
            $parts = explode('-', $traceparent);

            if (
                \count($parts) === 4
                && $parts[0] === '00'
                && \strlen($parts[1]) === 32 && ctype_xdigit($parts[1])
                && \strlen($parts[2]) === 16 && ctype_xdigit($parts[2])
                && \strlen($parts[3]) === 2 && ctype_xdigit($parts[3])
            ) {
                $span->attributes['span.trace_id'] = $parts[1];
                $span->attributes['span.parent_id'] = $parts[2];
            }
        }

        if (self::$storage instanceof \Utopia\Span\Storage\Storage) {
            self::$storage->set($span);
        }

        return $span;
    }

    /**
     * Get the current span from storage.
     *
     * @return self|null The current span, or null if no span is active
     */
    public static function current(): ?self
    {
        return self::$storage?->get();
    }

    /**
     * Set an attribute on the current span.
     *
     * Convenience method to add attributes without holding a span reference.
     * Does nothing if no span is active.
     *
     * @param string $key Attribute name (e.g., 'user.id', 'http.status')
     * @param string|int|float|bool|null $value Attribute value (scalars only)
     */
    public static function add(string $key, string|int|float|bool|null $value): void
    {
        self::current()?->set($key, $value);
    }

    /**
     * Get the traceparent header value from the current span.
     *
     * Use this to propagate trace context to downstream services.
     *
     * @return string|null W3C traceparent header value, or null if no span is active
     */
    public static function traceparent(): ?string
    {
        return self::current()?->getTraceparent();
    }

    /**
     * Set an attribute on this span.
     *
     * @param string $key Attribute name (e.g., 'user.id', 'http.status')
     * @param string|int|float|bool|null $value Attribute value (scalars only)
     * @return self For method chaining
     */
    public function set(string $key, string|int|float|bool|null $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Capture an exception on this span.
     *
     * Exporters can access the full exception including stacktrace via getError().
     *
     * @param Throwable $error The exception to capture
     * @return self For method chaining
     */
    public function setError(Throwable $error): self
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Get the captured exception.
     *
     * @return Throwable|null The captured exception, or null if none
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * Get the span action.
     *
     * @return string What this span represents
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get an attribute value.
     *
     * @param string $key Attribute name
     * @return string|int|float|bool|null The value, or null if not set
     */
    public function get(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get the W3C Trace Context traceparent header value
     *
     * Returns a traceparent header in the format: {version}-{trace_id}-{parent_id}-{flags}
     * Example: 00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01
     */
    public function getTraceparent(): string
    {
        return \sprintf(
            '00-%s-%s-01',
            $this->attributes['span.trace_id'],
            $this->attributes['span.id'],
        );
    }

    /**
     * Get all attributes
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Finish this span and export it.
     *
     * Sets span.finished_at and span.duration, then sends to all exporters
     * that pass their sampler (if any). Clears the current span from storage.
     *
     * @param Level|null $level Level to export for this span
     * @param Throwable|null $error Exception that caused the span to fail
     */
    public function finish(?Level $level = null, ?Throwable $error = null): void
    {
        if ($error instanceof \Throwable) {
            $this->setError($error);
        }

        $finishedAt = microtime(true);
        /** @var float $startedAt */
        $startedAt = $this->attributes['span.started_at'];

        $this->attributes['span.finished_at'] = $finishedAt;
        $this->attributes['span.duration'] = $finishedAt - $startedAt;

        $level ??= $this->error instanceof \Throwable ? Level::Error : Level::Info;
        $this->attributes['level'] = $level->value;

        foreach (self::$exporters as $exporter) {
            try {
                if ($exporter->sample($this)) {
                    $exporter->export($this);
                }
            } catch (\Throwable) {
                // Tracing should never break the application
            }
        }

        if (self::$storage instanceof \Utopia\Span\Storage\Storage) {
            self::$storage->set(null);
        }
    }
}
