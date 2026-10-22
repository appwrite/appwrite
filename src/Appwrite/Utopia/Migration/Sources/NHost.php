<?php

namespace Appwrite\Utopia\Migration\Sources;

use Appwrite\Network\Validator\Label;
use PDO;
use Utopia\Migration\Sources\NHost as Base;
use Utopia\Validator\Identifier;

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
            // Migrations stored before these were validated may hold DSN separators
            if (!(new Label())->isValid($this->subdomain) || !(new Label())->isValid($this->region) || !(new Identifier(63))->isValid($this->databaseName)) {
                throw new \Exception('Invalid NHost subdomain, region or database name.');
            }

            try {
                $this->connection = new PDO('pgsql:dbname=' . $this->databaseName . ';port=' . $this->port . ';host=' . self::databaseHost($this->subdomain, $this->region) . ';hostaddr=' . $this->address, $this->username, $this->password);
            } catch (\PDOException $e) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage(), self::statusCodeFromThrowable($e), $e);
            }
        }

        return $this->connection;
    }
}
