<?php

namespace Appwrite\Utopia\Migration\Sources;

use PDO;
use Utopia\Migration\Sources\NHost as Base;

/**
 * NHost source whose Postgres connection goes to an address checked beforehand. The hostname
 * still names the server for TLS, while libpq's hostaddr skips a second DNS lookup.
 */
class NHost extends Base
{
    private ?PDO $connection = null;

    public function __construct(
        string $subdomain,
        string $region,
        string $adminSecret,
        string $databaseName,
        string $username,
        string $password,
        string $port,
        private readonly string $address,
    ) {
        parent::__construct($subdomain, $region, $adminSecret, $databaseName, $username, $password, $port);
    }

    public static function databaseHost(string $subdomain, string $region): string
    {
        return "{$subdomain}.db.{$region}.nhost.run";
    }

    public static function storageHost(string $subdomain, string $region): string
    {
        return "{$subdomain}.storage.{$region}.nhost.run";
    }

    #[\Override]
    public function getDatabase(): PDO
    {
        if ($this->connection === null) {
            try {
                $this->connection = new PDO('pgsql:host=' . self::databaseHost($this->subdomain, $this->region) . ';hostaddr=' . $this->address . ';port=' . $this->port . ';dbname=' . $this->databaseName, $this->username, $this->password);
            } catch (\PDOException $e) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage(), self::statusCodeFromThrowable($e), $e);
            }
        }

        return $this->connection;
    }
}
