<?php

namespace Utopia\Database;

use InvalidArgumentException;
use Utopia\Console;

/**
 * A PDO wrapper that forwards method calls to the internal PDO instance.
 *
 * @mixin \PDO
 */
class PDO
{
    protected \PDO $pdo;

    private ?string $hostname = null;

    /**
     * @param string $dsn
     * @param ?string $username
     * @param ?string $password
     * @param array<mixed> $config
     */
    public function __construct(
        protected string $dsn,
        protected ?string $username,
        protected ?string $password,
        protected array $config = []
    ) {
        $this->config[\PDO::ATTR_ERRMODE] ??= \PDO::ERRMODE_EXCEPTION;

        $this->pdo = new \PDO(
            $this->dsn,
            $this->username,
            $this->password,
            $this->config
        );
    }

    /**
     * Prepare a statement, returning a wrapper that transparently re-prepares
     * itself on the underlying connection if that connection is lost before the
     * statement is executed.
     *
     * @param array<mixed> $options
     * @throws \Throwable
     */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        return new PDOStatement($this, $this->prepareNative($query, $options), $query, $options);
    }

    /**
     * Prepare a raw \PDOStatement on the underlying connection, reconnecting
     * once if a stale connection surfaces during prepare. Used by
     * {@see PDOStatement} to re-prepare after a reconnect without re-wrapping.
     *
     * Under emulated prepares this never reaches the server (so the loss
     * surfaces at execution time instead and is recovered by {@see PDOStatement});
     * with native prepares the server is contacted here, so a lost connection
     * outside a transaction is reconnected and retried, matching __call().
     *
     * @param array<mixed> $options
     * @throws \Throwable
     */
    public function prepareNative(string $query, array $options = []): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($query, $options);
        } catch (\Throwable $e) {
            if (!Connection::hasError($e) || $this->pdo->inTransaction()) {
                throw $e;
            }

            $this->reconnect();
            $statement = $this->pdo->prepare($query, $options);
        }

        if ($statement === false) {
            throw new \PDOException("Failed to prepare statement: {$query}");
        }

        return $statement;
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     * @return mixed
     * @throws \Throwable
     */
    public function __call(string $method, array $args): mixed
    {
        try {
            return $this->pdo->{$method}(...$args);
        } catch (\Throwable $e) {
            if (Connection::hasError($e)) {
                Console::warning('[Database] ' . $e->getMessage());
                Console::warning('[Database] Lost connection detected. Reconnecting...');

                $inTransaction = $this->pdo->inTransaction();

                // Attempt to reconnect
                $this->reconnect();

                // If we weren't in a transaction, also retry the query
                // In a transaction we can't retry as the state is attached to the previous connection
                if (!$inTransaction) {
                    return $this->pdo->{$method}(...$args);
                }
            }

            throw $e;
        }
    }

    /**
     * Create a new connection to the database
     *
     * @return void
     */
    public function reconnect(): void
    {
        $this->pdo = new \PDO(
            $this->dsn,
            $this->username,
            $this->password,
            $this->config
        );
    }

    /**
     * Get the hostname from the DSN.
     *
     * @return string
     * @throws \Exception
     */
    public function getHostname(): string
    {
        if ($this->hostname === null) {
            $parts = $this->parseDsn($this->dsn);

            /**
             * @var string $host
             */
            $host = $parts['host'] ?? throw new \Exception('No host found in DSN');

            $this->hostname = $host;
        }

        return $this->hostname;
    }

    /**
     * Parse a PDO-style DSN string.
     *
     * @return array<string, string|int|float|bool|null>
     * @throws InvalidArgumentException If the DSN is malformed.
     */
    private function parseDsn(string $dsn): array
    {
        if ($dsn === '' || !\str_contains($dsn, ':')) {
            throw new InvalidArgumentException('Malformed DSN: missing driver separator.');
        }

        [$driver, $parameterString] = \explode(':', $dsn, 2);

        $parsed = ['driver' => \trim($driver)];

        // Handle “path only” DSNs like sqlite:/path/to.db
        if (\in_array($driver, ['sqlite'], true) && $parameterString !== '') {
            $parsed['path'] = \ltrim($parameterString, '/');
            return $parsed;
        }

        $parameterSegments = \array_filter(\explode(';', $parameterString));

        foreach ($parameterSegments as $segment) {
            [$name, $rawValue] = \array_pad(\explode('=', $segment, 2), 2, null);

            $name  = \trim($name);
            $value = $rawValue !== null ? \trim($rawValue) : null;

            // Casting for scalars
            if ($value === 'true' || $value === 'false') {
                $value = $value === 'true';
            } elseif (\is_numeric($value)) {
                $value += 0;
            }

            $parsed[$name] = $value;
        }

        return $parsed;
    }
}
