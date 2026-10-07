<?php

namespace Utopia\Database;

use Utopia\Console;

/**
 * Wraps a \PDOStatement so a connection lost during execution is recovered
 * transparently: the owning PDO reconnects, the statement is re-prepared
 * against the fresh connection, previously bound parameters/columns/attributes
 * are replayed, and the failed execute() is retried.
 *
 * Recovery is attempted only for execute(), and only outside a transaction:
 * re-running any other method (fetch, rowCount, ...) without a fresh execute
 * would return data from an unexecuted statement, and a connection cannot be
 * healed in place mid-transaction (the uncommitted state is gone, so the call
 * is rethrown for Adapter::withTransaction to roll back and replay).
 *
 * @mixin \PDOStatement
 * @implements \IteratorAggregate<int, mixed>
 */
class PDOStatement implements \IteratorAggregate
{
    /**
     * @var array<int|string, array{mixed, int}>
     */
    private array $values = [];

    /**
     * @var array<int|string, array{mixed, int, int, mixed}>
     */
    private array $params = [];

    /**
     * The order bindValue()/bindParam() were called, so a placeholder rebound
     * across methods replays with the last binding winning, as PDO applies it.
     *
     * @var array<int, array{string, int|string}>
     */
    private array $bindOrder = [];

    /**
     * @var array<int|string, array{mixed, int, ?int, ?int, mixed}>
     */
    private array $columns = [];

    /**
     * @var array<int, mixed>
     */
    private array $attributes = [];

    /**
     * @var array<int|string, mixed>|null
     */
    private ?array $fetchMode = null;

    /**
     * @param array<mixed> $options
     */
    public function __construct(
        private readonly PDO $pdo,
        private \PDOStatement $statement,
        private readonly string $query,
        private readonly array $options = [],
    ) {
    }

    public function __get(string $name): mixed
    {
        return $this->statement->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->statement->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->statement->{$name});
    }

    public function __unset(string $name): void
    {
        unset($this->statement->{$name});
    }

    public function __clone(): void
    {
        throw new \Error('Trying to clone an uncloneable PDOStatement');
    }

    /**
     * Preserve \PDOStatement's native iterability (foreach over rows), which
     * does not route through __call().
     */
    public function getIterator(): \Traversable
    {
        return $this->statement;
    }

    /**
     * @param array<mixed> $args
     * @throws \Throwable
     */
    public function __call(string $method, array $args): mixed
    {
        try {
            return $this->statement->{$method}(...$args);
        } catch (\Throwable $e) {
            if (
                \strcasecmp($method, 'execute') !== 0
                || $this->pdo->inTransaction()
                || !Connection::hasError($e)
            ) {
                throw $e;
            }

            Console::warning('[Database] ' . $e->getMessage());
            Console::warning('[Database] Lost connection detected. Re-preparing statement...');

            $this->reprepare();

            return $this->statement->{$method}(...$args);
        }
    }

    public function getStatement(): \PDOStatement
    {
        return $this->statement;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return $this->statement->setAttribute($attribute, $value);
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = [$mode, ...$args];

        return $this->statement->setFetchMode($mode, ...$args);
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->values[$param] = [$value, $type];
        $this->bindOrder[] = ['value', $param];

        return $this->statement->bindValue($param, $value, $type);
    }

    public function bindParam(int|string $param, mixed &$variable, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        // Store the variable by reference so a value changed between bind and
        // execute is the value replayed after a reconnect (PDO binds late).
        $this->params[$param] = [&$variable, $type, $maxLength, $driverOptions];
        $this->bindOrder[] = ['param', $param];

        return $this->statement->bindParam($param, $variable, $type, $maxLength, $driverOptions);
    }

    public function bindColumn(int|string $column, mixed &$variable, ?int $type = null, ?int $maxLength = null, mixed $driverOptions = null): bool
    {
        // Record how many optional arguments were actually supplied so omitted
        // ones keep PDO's real defaults instead of being replayed as explicit
        // nulls (which would change the call contract / emit deprecations).
        $arity = \func_num_args();
        $this->columns[$column] = [&$variable, $arity, $type, $maxLength, $driverOptions];

        return $this->bindColumnTo($this->statement, $column, $variable, $arity, $type, $maxLength, $driverOptions);
    }

    private function reprepare(): void
    {
        $this->pdo->reconnect();
        $this->statement = $this->pdo->prepareNative($this->query, $this->options);

        foreach ($this->attributes as $attribute => $value) {
            $this->statement->setAttribute($attribute, $value);
        }

        if ($this->fetchMode !== null) {
            $this->statement->setFetchMode(...$this->fetchMode);
        }

        // Replay value/param bindings in the original call order so a placeholder
        // rebound across methods ends up with the binding the caller applied last.
        foreach ($this->bindOrder as [$kind, $key]) {
            if ($kind === 'value') {
                [$value, $type] = $this->values[$key];
                $this->statement->bindValue($key, $value, $type);
            } else {
                $bind = $this->params[$key];
                $this->statement->bindParam($key, $bind[0], $bind[1], $bind[2], $bind[3]);
            }
        }

        foreach ($this->columns as $column => $bind) {
            $this->bindColumnTo($this->statement, $column, $bind[0], $bind[1], $bind[2], $bind[3], $bind[4]);
        }
    }

    /**
     * Forward bindColumn passing only the optional arguments the caller
     * supplied ($arity counts column + variable + supplied options).
     */
    private function bindColumnTo(\PDOStatement $statement, int|string $column, mixed &$variable, int $arity, ?int $type = null, ?int $maxLength = null, mixed $driverOptions = null): bool
    {
        return match (true) {
            $arity <= 2 => $statement->bindColumn($column, $variable),
            $arity === 3 => $statement->bindColumn($column, $variable, $type ?? \PDO::PARAM_STR),
            $arity === 4 => $statement->bindColumn($column, $variable, $type ?? \PDO::PARAM_STR, $maxLength ?? 0),
            default => $statement->bindColumn($column, $variable, $type ?? \PDO::PARAM_STR, $maxLength ?? 0, $driverOptions),
        };
    }
}
