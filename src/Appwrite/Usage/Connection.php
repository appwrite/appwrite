<?php

namespace Appwrite\Usage;

use Psr\Http\Client\ClientInterface;
use Utopia\DSN\DSN;
use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Usage\Usage;

/**
 * Lifecycle-local connection to the shared OSS usage namespace.
 */
class Connection
{
    private ?Usage $usage = null;
    private bool $ready = false;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $dsn,
        private readonly ClientInterface $client,
        private readonly int $retention = 180,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUsage(): Usage
    {
        if (!$this->enabled) {
            throw new \RuntimeException('Usage statistics are disabled');
        }

        if ($this->usage !== null) {
            return $this->usage;
        }

        if ($this->dsn === '') {
            throw new \RuntimeException('Usage database connection not configured (_APP_CONNECTIONS_DB_USAGE)');
        }

        try {
            $dsn = new DSN($this->dsn);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Invalid _APP_CONNECTIONS_DB_USAGE DSN: ' . $e->getMessage(), previous: $e);
        }

        $secure = strtolower(trim((string) $dsn->getParam('secure', ''))) === 'true';
        $adapter = new ClickHouse(
            host: $dsn->getHost(),
            port: $dsn->getPort(),
            username: $dsn->getUser(),
            password: $dsn->getPassword(),
            secure: $secure,
            client: $this->client,
            namespace: 'projects',
            database: ltrim($dsn->getPath(), '/'),
            sharedTables: true,
            retention: $this->retention > 0 ? $this->retention : null,
        );

        return $this->usage = new Usage($adapter);
    }

    /** @return array<string, mixed> */
    public function healthCheck(): array
    {
        if (!$this->enabled) {
            return ['healthy' => true, 'enabled' => false, 'schemaReady' => false, 'status' => 'disabled'];
        }

        $usage = $this->getUsage();
        $health = $usage->healthCheck();
        if (($health['healthy'] ?? false) !== true) {
            return ['enabled' => true, 'schemaReady' => false] + $health;
        }

        try {
            $usage->findAcrossTenants([Query::limit(1)], Usage::TYPE_EVENT);
            $usage->findAcrossTenants([Query::limit(1)], Usage::TYPE_GAUGE);
            $usage->findDaily('__health__', [Query::limit(1)]);
        } catch (\Throwable $th) {
            return [
                'enabled' => true,
                'healthy' => false,
                'schemaReady' => false,
                'error' => $th->getMessage(),
            ] + $health;
        }

        $this->ready = true;
        return ['enabled' => true, 'schemaReady' => true] + $health;
    }

    public function isReady(): bool
    {
        if ($this->ready) {
            return true;
        }

        return ($this->healthCheck()['schemaReady'] ?? false) === true;
    }

    public function setup(): void
    {
        if ($this->enabled) {
            $this->ready = false;
            $this->getUsage()->setup();
        }
    }
}
