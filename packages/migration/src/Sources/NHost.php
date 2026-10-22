<?php

namespace Utopia\Migration\Sources;

use PDO;
use Utopia\Migration\Exception;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\Hash;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\BigInt;
use Utopia\Migration\Resources\Database\Columns\Boolean;
use Utopia\Migration\Resources\Database\Columns\DateTime;
use Utopia\Migration\Resources\Database\Columns\Decimal;
use Utopia\Migration\Resources\Database\Columns\Integer;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Migration\Warning;

class NHost extends Source
{
    protected static function statusCodeFromSqlState(?string $sqlState): int
    {
        if ($sqlState === null || $sqlState === '') {
            return Exception::CODE_INTERNAL;
        }

        // Common Postgres SQLSTATE mappings → HTTP-ish status codes
        // Ref: https://www.postgresql.org/docs/current/errcodes-appendix.html
        return match ($sqlState) {
            // AuthN/AuthZ
            '28P01', // invalid_password
            '28000', // invalid_authorization_specification
            => Exception::CODE_UNAUTHORIZED,

            '42501', // insufficient_privilege
            => Exception::CODE_FORBIDDEN,

            // Not found
            '3D000', // invalid_catalog_name (db does not exist)
            '42P01', // undefined_table
            => Exception::CODE_NOT_FOUND,

            // Validation-ish
            '42703', // undefined_column
            '42601', // syntax_error
            => Exception::CODE_VALIDATION,

            // Conflict
            '23505', // unique_violation
            => Exception::CODE_CONFLICT,

            // Resource / service constraints
            '53300', // too_many_connections
            => Exception::CODE_RATE_LIMITED,

            // Connection exceptions (class 08) are typically user-side config/network issues
            default => \str_starts_with($sqlState, '08')
                ? Exception::CODE_VALIDATION
                : Exception::CODE_INTERNAL,
        };
    }

    protected static function statusCodeFromThrowable(\Throwable $e): int
    {
        $code = $e->getCode();

        // PDO / Postgres typically exposes SQLSTATE as the exception code (string)
        if (\is_string($code)) {
            return self::statusCodeFromSqlState($code);
        }

        if (\is_int($code) && $code > 0) {
            return $code;
        }

        return Exception::CODE_INTERNAL;
    }

    /**
     * @param array<int, mixed>|null $errorInfo PDOStatement::errorInfo()
     */
    protected static function statusCodeFromErrorInfo(?array $errorInfo): int
    {
        $sqlState = $errorInfo[0] ?? null;
        return self::statusCodeFromSqlState(\is_string($sqlState) ? $sqlState : null);
    }
    /**
     * @var \PDO
     */
    public $pdo;

    public string $subdomain;

    public string $region;

    public string $databaseName;

    public string $username;

    public string $password;

    public string $port;

    public string $adminSecret;

    public string $storageURL;

    protected string $databaseAddress = '';

    public function __construct(string $subdomain, string $region, string $adminSecret, string $databaseName, string $username, string $password, string $port = '5432')
    {
        $this->subdomain = $subdomain;
        $this->region = $region;
        $this->adminSecret = $adminSecret;
        $this->databaseName = $databaseName;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->storageURL = "https://{$this->subdomain}.storage.{$this->region}.nhost.run";
    }

    /**
     * The Postgres host the source connects to, so a caller can check where it resolves before connecting.
     */
    public function getDatabaseHost(): string
    {
        return $this->subdomain.'.db.'.$this->region.'.nhost.run';
    }

    /**
     * Connect to the database host on this address (libpq hostaddr), so the connection lands where the caller
     * checked rather than wherever the host resolves at connect time. TLS still verifies the host. Set it before
     * the first connection.
     */
    public function setDatabaseAddress(string $address): static
    {
        $this->databaseAddress = $address;

        return $this;
    }

    public function getDatabaseDsn(): string
    {
        $address = $this->databaseAddress === '' ? '' : ';hostaddr='.$this->databaseAddress;

        return 'pgsql:host='.$this->getDatabaseHost().$address.';port='.$this->port.';dbname='.$this->databaseName;
    }

    public function getDatabase(): PDO
    {
        if (! $this->pdo) {
            try {
                $this->pdo = new \PDO($this->getDatabaseDsn(), $this->username, $this->password);
            } catch (\PDOException $e) {
                throw new \Exception('Failed to connect to database: '.$e->getMessage(), self::statusCodeFromThrowable($e), $e);
            }
        }

        return $this->pdo;
    }

    public static function getName(): string
    {
        return 'NHost';
    }

    public static function getSupportedResources(): array
    {
        return [
            // Auth
            Resource::TYPE_USER,

            // Database
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX,
            Resource::TYPE_ROW,

            // LEGACY
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_COLLECTION,

            // Storage
            Resource::TYPE_BUCKET,
            Resource::TYPE_FILE,
        ];
    }

    public function report(array $resources = [], array $resourceIds = []): array
    {
        $report = [];

        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        try {
            $db = $this->getDatabase();
        } catch (\PDOException $e) {
            throw new \Exception('Failed to connect to database. PDO Code: '.$e->getCode().' Error: '.$e->getMessage(), self::statusCodeFromThrowable($e), $e);
        }

        if (! empty($db->errorCode())) {
            throw new \Exception(
                'Failed to connect to database. PDO Code: '.$db->errorCode().(empty($db->errorInfo()[2]) ? '' : ' Error: '.$db->errorInfo()[2]),
                self::statusCodeFromSqlState($db->errorCode())
            );
        }

        // Auth
        if (\in_array(Resource::TYPE_USER, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM auth.users');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access users table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_USER] = $statement->fetchColumn();
        }

        // Databases
        if (\in_array(Resource::TYPE_DATABASE, $resources)) {
            $report[Resource::TYPE_DATABASE] = 1;
        }

        if (Resource::isSupported(Resource::TYPE_TABLE, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access tables table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_TABLE] = $statement->fetchColumn();
        }

        if (Resource::isSupported(Resource::TYPE_COLUMN, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access columns table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_COLUMN] = $statement->fetchColumn();
        }

        if (\in_array(Resource::TYPE_INDEX, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access indexes table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_INDEX] = $statement->fetchColumn();
        }

        if (Resource::isSupported(Resource::TYPE_ROW, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access tables table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_ROW] = $statement->fetchColumn();
        }

        // Storage
        if (\in_array(Resource::TYPE_BUCKET, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM storage.buckets');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access buckets table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_BUCKET] = $statement->fetchColumn();
        }

        if (\in_array(Resource::TYPE_FILE, $resources)) {
            $statement = $db->prepare('SELECT COUNT(*) FROM storage.files');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access files table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_FILE] = $statement->fetchColumn();

            $statement = $db->prepare('SELECT SUM(storage.files."size") from storage.files;');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access files table. Error: '.$statement->errorInfo()[2],
                    self::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report['size'] = ($statement->fetchColumn()) / 1024 / 1024; // MB;
        }

        $this->previousReport = $report;

        return $report;
    }

    protected function exportGroupAuth(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_USER, $resources)) {
                $this->exportUsers($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_USER,
                Transfer::GROUP_AUTH,
                message: $e->getMessage(),
                code: self::statusCodeFromThrowable($e),
                previous: $e
            ));
        }
    }

    private function exportUsers(int $batchSize): void
    {
        $db = $this->getDatabase();

        $total = $db->query('SELECT COUNT(*) FROM auth.users')->fetchColumn();

        $offset = 0;

        while ($offset < $total) {
            $statement = $db->prepare('SELECT * FROM auth.users order by created_at LIMIT :limit OFFSET :offset');
            $statement->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $statement->execute();

            $users = $statement->fetchAll(\PDO::FETCH_ASSOC);

            $offset += $batchSize;

            $transferUsers = [];

            foreach ($users as $user) {
                $hash = null;

                if (array_key_exists('password_hash', $user)) {
                    $hash = new Hash($user['password_hash'], '', Hash::ALGORITHM_BCRYPT);
                }

                $transferUser = new User(
                    $user['id'],
                    $user['email'] ?? null,
                    $user['display_name'] ?? null,
                    $hash,
                    $user['phone_number'] ?? null,
                    [],
                    '',
                    $user['email_verified'] ?? false,
                    $user['phone_number_verified'] ?? false,
                    $user['disabled'] ?? false,
                    []
                );

                $transferUsers[] = $transferUser;
            }

            $this->callback($transferUsers);
        }
    }

    protected function exportGroupDatabases(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_DATABASE, $resources)) {
                $this->exportDatabases($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_DATABASE,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }

        try {
            if (Resource::isSupported(Resource::TYPE_TABLE, $resources)) {
                $this->exportTables($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_TABLE,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }

        try {
            if (Resource::isSupported(Resource::TYPE_COLUMN, $resources)) {
                $this->exportColumns($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_COLUMN,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }

        try {
            if (Resource::isSupported(Resource::TYPE_ROW, $resources)) {
                $this->exportRows($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_ROW,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }

        try {
            if (\in_array(Resource::TYPE_INDEX, $resources)) {
                $this->exportIndexes($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_INDEX,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }
    }

    private function exportDatabases(int $batchSize): void
    {
        // We'll only transfer the public database for now, since it's the only one that exists by default.
        //TODO: Handle edge cases where there are user created databases and data.
        $transferDatabase = new Database('public', 'public');
        $this->callback([$transferDatabase]);
    }

    private function exportTables(int $batchSize): void
    {
        $databases = $this->cache->get(Database::getName());
        $db = $this->getDatabase();

        foreach ($databases as $database) {
            /** @var Database $database */
            $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :database');
            $statement->execute([':database' => $database->getId()]);
            $total = $statement->fetchColumn(0);

            $offset = 0;

            while ($offset < $total) {
                $statement = $db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = :database order by table_name LIMIT :limit OFFSET :offset');
                $statement->execute([':limit' => $batchSize, ':offset' => $offset, ':database' => $database->getId()]);

                $tables = $statement->fetchAll(\PDO::FETCH_ASSOC);

                $offset += $batchSize;

                $transferTables = [];

                foreach ($tables as $table) {
                    $transferTables[] = new Table($database, $table['table_name'], $table['table_name']);
                }

                $this->callback($transferTables);
            }
        }
    }

    private function exportColumns(int $batchSize): void
    {
        $tables = $this->cache->get(Table::getName());
        $db = $this->getDatabase();

        foreach ($tables as $table) {
            /** @var Table $table */
            $statement = $db->prepare('SELECT * FROM information_schema."columns" where "table_name" = :tableName');
            $statement->bindValue(':tableName', $table->getTableName());
            $statement->execute();
            $databaseTable = $statement->fetchAll(\PDO::FETCH_ASSOC);

            $columns = [];

            foreach ($databaseTable as $column) {
                $columns[] = $this->convertColumn($column, $table);
            }

            $this->callback($columns);
        }
    }

    private function exportIndexes(int $batchSize): void
    {
        $tables = $this->cache->get(Table::getName());
        $db = $this->getDatabase();

        foreach ($tables as $table) {
            /** @var Table $table */
            $indexStatement = $db->prepare('SELECT indexname, indexdef FROM pg_indexes WHERE tablename = :tableName');
            $indexStatement->bindValue(':tableName', $table->getTableName());
            $indexStatement->execute();

            $databaseIndexes = $indexStatement->fetchAll(\PDO::FETCH_ASSOC);
            $indexes = [];
            foreach ($databaseIndexes as $index) {
                $result = $this->convertIndex($index, $table);

                if ($result) {
                    $indexes[] = $result;
                }
            }

            $this->callback($indexes);
        }
    }

    private function exportRows(int $batchSize): void
    {
        $databases = $this->cache->get(Database::getName());
        $tables = $this->cache->get(Table::getName());
        $db = $this->getDatabase();

        foreach ($databases as $database) {
            /** @var Database $database */
            $tables = array_filter($tables, function (Table $table) use ($database) {
                return $table->getDatabase()->getId() === $database->getId();
            });

            foreach ($tables as $table) {
                /** @var Table $table */
                $total = $db->query('SELECT COUNT(*) FROM '.$table->getDatabase()->getDatabaseName().'."'.$table->getTableName().'"')->fetchColumn();

                $offset = 0;

                while ($offset < $total) {
                    $statement = $db->prepare('SELECT row_to_json(t) FROM (SELECT * FROM '.$table->getDatabase()->getDatabaseName().'."'.$table->getTableName().'" LIMIT :limit OFFSET :offset) t;');
                    $statement->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
                    $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
                    $statement->execute();

                    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

                    $offset += $batchSize;

                    $transferRows = [];

                    $columns = $this->cache->get(Column::getName());
                    $tableColumns = array_filter($columns, function (Column $column) use ($table) {
                        return $column->getTable()->getId() === $table->getId();
                    });

                    foreach ($rows as $row) {
                        $data = json_decode($row['row_to_json'], true);

                        $processedData = [];
                        foreach ($tableColumns as $column) {
                            /** @var Column $column */
                            if (! $column->isArray() && \is_array($data[$column->getKey()])) {
                                $processedData[$column->getKey()] = json_encode($data[$column->getKey()]);
                            } else {
                                $processedData[$column->getKey()] = $data[$column->getKey()];
                            }
                        }

                        $transferRows[] = new Row('unique()', $table, $processedData);
                    }

                    $this->callback($transferRows);
                }
            }
        }
    }

    private function convertColumn(array $column, Table $table): Column
    {
        $isArray = $column['data_type'] === 'ARRAY';

        switch ($isArray ? str_replace('_', '', $column['udt_name']) : $column['data_type']) {
            // Numbers
            case 'boolean':
            case 'bool':
                return new Boolean(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: $column['column_default'],
                    array: $isArray,
                );
            case 'smallint':
            case 'int2':
                if (! is_numeric($column['column_default']) && ! is_null($column['column_default'])) {
                    $this->addWarning(new Warning(
                        Resource::TYPE_TABLE,
                        Transfer::GROUP_DATABASES,
                        'Functional default values are not supported. Default value for column '.$column['column_name'].' will be set to null.',
                        $table->getId()
                    ));

                    $table->setStatus(Resource::STATUS_WARNING);

                    $column['column_default'] = null;
                }

                return new Integer(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default:$column['column_default'],
                    array: $isArray,
                    min: -32768,
                    max: 32767,
                );
            case 'integer':
            case 'int4':
                if (! is_numeric($column['column_default']) && ! is_null($column['column_default'])) {
                    $this->addWarning(new Warning(
                        Resource::TYPE_TABLE,
                        Transfer::GROUP_DATABASES,
                        'Functional default values are not supported. Default value for column '.$column['column_name'].' will be set to null.',
                        $table->getId()
                    ));

                    $table->setStatus(Resource::STATUS_WARNING);

                    $column['column_default'] = null;
                }

                return new Integer(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: $column['column_default'],
                    array: $isArray,
                    min: -2147483648,
                    max: 2147483647,
                );
            case 'bigint':
            case 'int8':
            case 'numeric':
                if (! is_numeric($column['column_default']) && ! is_null($column['column_default'])) {
                    $this->addWarning(new Warning(
                        Resource::TYPE_TABLE,
                        Transfer::GROUP_DATABASES,
                        'Functional default values are not supported. Default value for column '.$column['column_name'].' will be set to null.',
                        $table->getId()
                    ));
                    $table->setStatus(Resource::STATUS_WARNING);

                    $column['column_default'] = null;
                }

                return new BigInt(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: $column['column_default'],
                    array: $isArray,
                );
            case 'decimal':
            case 'real':
            case 'double precision':
            case 'float4':
            case 'float8':
            case 'money':
                if (! is_numeric($column['column_default']) && ! is_null($column['column_default'])) {
                    $this->addWarning(new Warning(
                        Resource::TYPE_TABLE,
                        Transfer::GROUP_DATABASES,
                        'Functional default values are not supported. Default value for column '.$column['column_name'].' will be set to null.',
                        $table->getId()
                    ));

                    $table->setStatus(Resource::STATUS_WARNING);

                    $column['column_default'] = null;
                }

                return new Decimal(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: $column['column_default'],
                    array: $isArray,
                );
                // Time (Conversion happens with documents)
            case 'timestamp with time zone':
            case 'date':
            case 'time with time zone':
            case 'timestamp without time zone':
            case 'timestamptz':
            case 'timestamp':
            case 'time':
            case 'timetz':
            case 'interval':
                return new DateTime(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: null,
                    array: $isArray,
                );
            default:
                // Strings and Objects
                return new Text(
                    $column['column_name'],
                    $table,
                    required: $column['is_nullable'] === 'NO',
                    default: $column['column_default'],
                    array: $isArray,
                    size: $column['character_maximum_length'] ?? $column['character_octet_length'] ?? 10485760,
                );
        }
    }

    private function convertIndex(array $index, Table $table): Index|false
    {
        $pattern = "/CREATE (?<type>\w+)? INDEX (?<name>\w+) ON (?<table>\w+\.\w+) USING (?<method>\w+) \((?<columns>\w+)\)/";

        if (\preg_match($pattern, $index['indexdef'], $matches)) {
            // We only support BTree indexes
            if ($matches['method'] !== 'btree') {
                //TODO: Figure out how to deal with warnings
                // Add warning here for unsupported index type
                return false;
            }

            $type = '';

            if ($matches['type'] === 'UNIQUE') {
                $type = Index::TYPE_UNIQUE;
            } elseif ($matches['type'] === 'FULLTEXT') {
                $type = Index::TYPE_FULLTEXT;
            } else {
                $type = Index::TYPE_KEY;
            }

            $columns = [];
            $order = [];

            $targets = explode(',', $matches['columns']);

            foreach ($targets as $target) {
                if (str_contains($target, ' ')) {
                    [$column, $direction] = \explode(' ', $target);
                    $columns[] = $column;
                    $order[] = $direction;
                } else {
                    $columns[] = $target;
                    $order[] = 'ASC';
                }
            }

            return new Index($matches['name'], $matches['name'], $table, $type, $columns, $order);
        } else {
            return false;
        }
    }

    protected function exportGroupStorage(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_BUCKET, $resources)) {
                $this->exportBuckets($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_BUCKET,
                    Transfer::GROUP_STORAGE,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }

        try {
            if (\in_array(Resource::TYPE_FILE, $resources)) {
                $this->exportFiles($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    Resource::TYPE_FILE,
                    Transfer::GROUP_STORAGE,
                    message: $e->getMessage(),
                    code: self::statusCodeFromThrowable($e),
                    previous: $e
                )
            );
        }
    }

    protected function exportBuckets(int $batchSize): void
    {
        $db = $this->getDatabase();
        $total = $db->query('SELECT COUNT(*) FROM storage.buckets')->fetchColumn();

        $offset = 0;

        while ($offset < $total) {
            $statement = $db->prepare('SELECT * FROM storage.buckets order by created_at LIMIT :limit OFFSET :offset');
            $statement->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $statement->execute();

            $buckets = $statement->fetchAll(\PDO::FETCH_ASSOC);

            $offset += $batchSize;

            $transferBuckets = [];

            foreach ($buckets as $bucket) {
                $transferBuckets[] = new Bucket(
                    $bucket['id'],
                    $bucket['id']
                ); //TODO: To add file_size transfer then we need to be able to see the destination's limit on files.
            }

            $this->callback($transferBuckets);
        }
    }

    private function exportFiles(int $batchSize): void
    {
        $buckets = $this->cache->get(Bucket::getName());
        $db = $this->getDatabase();

        foreach ($buckets as $bucket) {
            /** @var Bucket $bucket */
            $totalStatement = $db->prepare('SELECT COUNT(*) FROM storage.files WHERE bucket_id=:bucketId');
            $totalStatement->execute([':bucketId' => $bucket->getId()]);
            $total = $totalStatement->fetchColumn();

            $offset = 0;
            while ($offset < $total) {
                $statement = $db->prepare('SELECT * FROM storage.files WHERE bucket_id=:bucketId order by created_at LIMIT :limit OFFSET :offset');
                $statement->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
                $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
                $statement->bindValue(':bucketId', $bucket->getId());
                $statement->execute();

                $files = $statement->fetchAll(\PDO::FETCH_ASSOC);

                $offset += $batchSize;

                foreach ($files as $file) {
                    $this->exportFile(new File(
                        $file['id'],
                        $bucket,
                        $file['name'],
                        '',
                        $file['mime_type'],
                        [],
                        $file['size'],
                    ));
                }
            }
        }
    }

    private function exportFile(File $file): void
    {
        $start = 0;
        $end = Transfer::STORAGE_MAX_CHUNK_SIZE - 1;

        $fileSize = $file->getSize();
        $response = $this->call('GET', $this->storageURL."/v1/files/{$file->getId()}/presignedurl", [
            'X-Hasura-Admin-Secret' => $this->adminSecret,
        ]);

        $fileUrl = $response['url'];
        $refreshTime = \time() + $response['expiration'];

        if ($end > $fileSize) {
            $end = $fileSize - 1;
        }

        while ($start < $fileSize) {
            if (\time() > $refreshTime) {
                $response = $this->call('GET', "/v1/files/{$file->getId()}/presignedurl", [
                    'X-Hasura-Admin-Secret' => $this->adminSecret,
                ]);

                $fileUrl = $response['url'];
                $refreshTime = \time() + $response['expiration'];
            }

            $chunkData = $this->call(
                'GET',
                $fileUrl,
                ['range' => "bytes=$start-$end"]
            );

            $file->setData($chunkData)
                ->setStart($start)
                ->setEnd($end);

            $this->callback([$file]);

            $start += Transfer::STORAGE_MAX_CHUNK_SIZE;
            $end += Transfer::STORAGE_MAX_CHUNK_SIZE;

            if ($end > $fileSize) {
                $end = $fileSize - 1;
            }
        }
    }

    protected function exportGroupFunctions(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupMessaging(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupSites(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupIntegrations(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupBackups(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupProjects(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupDomains(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }
}
