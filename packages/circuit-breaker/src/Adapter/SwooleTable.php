<?php

namespace Utopia\CircuitBreaker\Adapter;

use Utopia\CircuitBreaker\Adapter as CircuitBreakerAdapter;

final readonly class SwooleTable implements CircuitBreakerAdapter
{
    public const VALUE_COLUMN = 'value';
    public const NUMBER_COLUMN = 'number';
    public const TYPE_COLUMN = 'type';

    private const DEFAULT_VALUE_LENGTH = 255;
    private const MAX_TABLE_KEY_LENGTH = 63;
    private const TYPE_STRING = 1;
    private const TYPE_INT = 2;

    /**
     * @param object $table A Swoole\Table created with value, number, and type columns.
     */
    public function __construct(
        private object $table,
        private string $prefix = 'breaker:',
    ) {
        foreach (['get', 'set', 'incr', 'del'] as $method) {
            if (!method_exists($this->table, $method)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s requires a Swoole table-compatible object with a %s() method.',
                    self::class,
                    $method,
                ));
            }
        }
    }

    public static function createTable(
        int $size = 1024,
        int $valueLength = self::DEFAULT_VALUE_LENGTH,
    ): \Swoole\Table {
        if (!class_exists(\Swoole\Table::class)) {
            throw new AdapterException('The swoole extension is required to create a Swoole table.');
        }

        $table = new \Swoole\Table($size);
        $table->column(self::VALUE_COLUMN, \Swoole\Table::TYPE_STRING, $valueLength);
        $table->column(self::NUMBER_COLUMN, \Swoole\Table::TYPE_INT);
        $table->column(self::TYPE_COLUMN, \Swoole\Table::TYPE_INT);
        $table->create();

        return $table;
    }

    public function get(string $key): int|string|null
    {
        $row = $this->command('get', [$this->key($key)]);

        if ($row === false || $row === null) {
            return null;
        }

        if (!\is_array($row)) {
            throw new AdapterException(\sprintf('Unexpected Swoole table row for cache key "%s".', $key));
        }

        $type = (int) ($row[self::TYPE_COLUMN] ?? 0);

        if ($type === self::TYPE_STRING) {
            return (string) ($row[self::VALUE_COLUMN] ?? '');
        }

        if ($type === self::TYPE_INT) {
            return (int) ($row[self::NUMBER_COLUMN] ?? 0);
        }

        throw new AdapterException(\sprintf('Unexpected Swoole table value type for cache key "%s".', $key));
    }

    public function set(string $key, int|string $value): void
    {
        $row = \is_int($value)
            ? [self::VALUE_COLUMN => '', self::NUMBER_COLUMN => $value, self::TYPE_COLUMN => self::TYPE_INT]
            : [self::VALUE_COLUMN => $value, self::NUMBER_COLUMN => 0, self::TYPE_COLUMN => self::TYPE_STRING];

        $result = $this->command('set', [$this->key($key), $row]);

        if ($result === false) {
            throw new AdapterException(\sprintf('Failed to set Swoole table key "%s".', $key));
        }
    }

    public function increment(string $key, int $by = 1): int
    {
        $tableKey = $this->key($key);
        $row = $this->command('get', [$tableKey]);

        if (\is_array($row)) {
            $type = (int) ($row[self::TYPE_COLUMN] ?? 0);

            if ($type === self::TYPE_STRING) {
                throw new AdapterException(\sprintf('Cannot increment non-numeric Swoole table key "%s".', $key));
            }
        } elseif ($row !== false && $row !== null) {
            throw new AdapterException(\sprintf('Unexpected Swoole table row for cache key "%s".', $key));
        }

        $value = $this->command('incr', [$tableKey, self::NUMBER_COLUMN, $by]);

        if ($value === false || $value === null) {
            throw new AdapterException(\sprintf('Failed to increment Swoole table key "%s".', $key));
        }

        $result = $this->command('set', [$tableKey, [self::TYPE_COLUMN => self::TYPE_INT]]);
        if ($result === false) {
            throw new AdapterException(\sprintf('Failed to update Swoole table value type for key "%s".', $key));
        }

        return (int) $value;
    }

    public function delete(string $key): void
    {
        $this->command('del', [$this->key($key)]);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function command(string $method, array $arguments): mixed
    {
        try {
            return $this->table->{$method}(...$arguments);
        } catch (\Throwable $exception) {
            throw new AdapterException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    private function key(string $key): string
    {
        $tableKey = $this->prefix . $key;

        if (\strlen($tableKey) <= self::MAX_TABLE_KEY_LENGTH) {
            return $tableKey;
        }

        return substr($this->prefix, 0, 20) . sha1($tableKey);
    }
}
