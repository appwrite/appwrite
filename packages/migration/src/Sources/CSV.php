<?php

namespace Utopia\Migration\Sources;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipType;
use Utopia\Migration\Exception;
use Utopia\Migration\Resource;
use Utopia\Migration\Resource as UtopiaResource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite\Reader;
use Utopia\Migration\Sources\Appwrite\Reader\Database as DatabaseReader;
use Utopia\Migration\Transfer;
use Utopia\Migration\Warning;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;

class CSV extends Source
{
    private const TEXT_TYPES = [
        Column::TYPE_STRING,
        Column::TYPE_VARCHAR,
        Column::TYPE_TEXT,
        Column::TYPE_MEDIUMTEXT,
        Column::TYPE_LONGTEXT,
    ];

    /**
     * Rejected rows listed in the errors; every one is still counted.
     */
    private const MAX_ROW_ERRORS = 100;

    private const ALLOWED_INTERNALS = [
        '$id' => true,
        '$permissions' => true,
        '$createdAt' => true,
        '$updatedAt' => true,
    ];

    private string $filePath;

    /**
     * Legacy format: `{databaseId}:{tableId}`.
     */
    private string $resourceId;

    private ?string $resourceChildId = null;

    private Device $device;

    private Reader $database;

    private bool $downloaded = false;

    private int $rejectedRows = 0;

    public function __construct(
        string $resourceId,
        string $filePath,
        Device $device,
        ?UtopiaDatabase $dbForProject,
        ?callable $getDatabasesDB = null,
    ) {
        $this->device = $device;
        $this->filePath = $filePath;
        $this->resourceId = $resourceId;
        $this->database = new DatabaseReader($dbForProject, $getDatabasesDB);
    }

    public static function fromResourceIds(
        string $databaseId,
        string $tableId,
        string $filePath,
        Device $device,
        ?UtopiaDatabase $dbForProject,
        ?callable $getDatabasesDB = null,
    ): self {
        $source = new self(
            resourceId: $databaseId,
            filePath: $filePath,
            device: $device,
            dbForProject: $dbForProject,
            getDatabasesDB: $getDatabasesDB,
        );
        $source->resourceChildId = $tableId;

        return $source;
    }

    public static function getName(): string
    {
        return 'CSV';
    }

    public static function getSupportedResources(): array
    {
        return [
            UtopiaResource::TYPE_ROW,
            UtopiaResource::TYPE_DOCUMENT,
        ];
    }

    // called before the `exportGroupDatabases`.

    /**
     * @throws \Exception
     */
    public function report(array $resources = [], array $resourceIds = []): array
    {
        $report = [];

        if (!$this->device->exists($this->filePath)) {
            return $report;
        }

        $this->downloadToLocal(
            $this->device,
            $this->filePath,
        );

        $file = new \SplFileObject($this->filePath, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

        $file->seek(PHP_INT_MAX);
        $rowCount = max(0, $file->key());

        $report[UtopiaResource::TYPE_ROW] = $rowCount;

        return $report;
    }

    /**
     * @throws \Exception
     */
    protected function exportGroupAuth(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    protected function exportGroupDatabases(int $batchSize, array $resources): void
    {
        try {
            if (UtopiaResource::isSupported($this->getSupportedResources(), $resources)) {
                $this->exportRows($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(
                new Exception(
                    UtopiaResource::TYPE_ROW,
                    Transfer::GROUP_DATABASES,
                    message: $e->getMessage(),
                    code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                    previous: $e
                )
            );
        } finally {
            // delete the temporary file!
            $this->device->delete($this->filePath);
        }
    }

    /**
     * @throws \Exception
     */
    private function exportRows(int $batchSize): void
    {
        $columns = [];
        $lastColumn = null;
        [$databaseId, $tableId] = $this->getResourceIds();

        $databases = $this->database->listDatabases([
            $this->database->queryEqual('$id', [$databaseId]),
            $this->database->queryLimit(1),
        ]);

        if (empty($databases)) {
            throw new \Exception('Database not found');
        }

        $databaseDocument = $databases[0];
        $databaseType = $databaseDocument->getAttribute('type', UtopiaResource::TYPE_DATABASE);
        if (\in_array($databaseType, [UtopiaResource::TYPE_DATABASE_LEGACY, UtopiaResource::TYPE_DATABASE_TABLESDB], true)) {
            $databaseType = UtopiaResource::TYPE_DATABASE;
        }

        $databasePayload = [
            'id' => $databaseDocument->getId(),
            'name' => $databaseDocument->getAttribute('name', $databaseDocument->getId()),
            'originalId' => $databaseDocument->getAttribute('originalId', ''),
            'type' => $databaseType,
            'database' => $databaseDocument->getAttribute('database', ''),
        ];

        $tablePayload = [
            'id' => $tableId,
            'name' => $tableId,
            'documentSecurity' => false,
            'rowSecurity' => false,
            'permissions' => [],
            'createdAt' => '',
            'updatedAt' => '',
            'database' => [
                'id' => $databasePayload['id'],
                'name' => $databasePayload['name'],
                'type' => $databasePayload['type'],
                'database' => $databasePayload['database'],
            ],
        ];

        $table = Appwrite::getEntity($databaseType, $tablePayload);

        while (true) {
            $queries = [$this->database->queryLimit($batchSize)];

            if ($lastColumn) {
                $queries[] = $this->database->queryCursorAfter($lastColumn);
            }

            $fetched = $this->database->listColumns($table, $queries);
            if (empty($fetched)) {
                break;
            }

            \array_push($columns, ...$fetched);
            $lastColumn = $fetched[\count($fetched) - 1];

            if (\count($fetched) < $batchSize) {
                break;
            }
        }

        $arrayKeys = [
            '$permissions' => true,
        ];
        $columnTypes = [];
        $requiredColumns = [];
        $manyToManyKeys = [];
        $textKeys = [];

        foreach ($columns as $column) {
            $key = $column['key'];
            $type = $column['type'];
            $isArray = $column['array'] ?? false;
            $isRequired = $column['required'] ?? false;
            $relationSide = $column['side'] ?? '';
            $relationType = $column['relationType'] ?? '';

            if (
                $type === Column::TYPE_RELATIONSHIP &&
                $relationSide === RelationshipSide::Child->value
            ) {
                continue;
            }

            $columnTypes[$key] = $type;
            if ($isRequired) {
                $requiredColumns[$key] = true;
            }

            if (
                $type === Column::TYPE_RELATIONSHIP &&
                $relationType === RelationshipType::ManyToMany->value &&
                $relationSide === RelationshipSide::Parent->value
            ) {
                $manyToManyKeys[$key] = true;
            }

            if ($isArray && $type !== Column::TYPE_RELATIONSHIP) {
                $arrayKeys[$key] = true;
            }

            // Formats (email, enum, ip, url) have no empty value, so they are not text here.
            if (\in_array($type, self::TEXT_TYPES, true) && empty($column['format'])) {
                $textKeys[$key] = true;
            }
        }

        $this->withCsvStream(function ($stream, $delimiter) use ($columnTypes, $databaseType, $requiredColumns, $manyToManyKeys, $arrayKeys, $textKeys, $tablePayload, $batchSize) {
            $headers = \fgetcsv($stream, 0, $delimiter, '"', '"');

            if (!\is_array($headers) || \count($headers) === 0) {
                return;
            }

            $this->validateCSVHeaders($headers, $columnTypes, $requiredColumns);

            $buffer = [];
            $rowNumber = 1; // The header is row 1, as in a spreadsheet.

            while (true) {
                $offset = \ftell($stream);
                $row = \fgetcsv($stream, 0, $delimiter, '"', '"');
                if ($row === false) {
                    break;
                }

                $rowNumber++;

                if ($row === [null]) {
                    continue; // Blank line
                }

                if (\count($row) !== \count($headers)) {
                    $this->rejectRow($databaseType, $tablePayload, null, "Row $rowNumber has " . \count($row) . ' columns, but the header has ' . \count($headers) . '.');
                    continue;
                }

                // PDO drops a whole batch without an error when one value is not UTF-8.
                if (!\mb_check_encoding($row, 'UTF-8')) {
                    $this->rejectRow($databaseType, $tablePayload, null, "Row $rowNumber is not valid UTF-8.");
                    continue;
                }

                $data = \array_combine($headers, $row);
                $parsedData = $data;
                $quoted = null;

                /**
                 * Parsing logic for best compatibility with spec and 3rd party tools.
                 * - 'null' unquoted literal is converted to null.
                 * - text keeps its whitespace and an empty cell stays an empty string.
                 * - an empty cell of any other column is null.
                 * - other values are parsed as per their type; a value that does not parse rejects the row.
                 */
                try {
                    foreach ($headers as $index => $key) {
                        $type = $columnTypes[$key] ?? null;

                        if (!isset($type) && $key !== '$permissions') {
                            // Skip unknown columns
                            continue;
                        }

                        $parsedValue = isset($textKeys[$key]) ? $row[$index] : \trim($row[$index]);

                        if (isset($manyToManyKeys[$key])) {
                            if ($parsedValue === '') {
                                $parsedData[$key] = [];
                            } else {
                                // Split on commas, trim whitespace, drop empty tokens, and reindex
                                $ids = \explode(',', $parsedValue);
                                $ids = \array_map(\trim(...), $ids);
                                $ids = \array_filter($ids, static fn ($id) => $id !== '');
                                $parsedData[$key] = \array_values($ids);
                            }
                            continue;
                        }

                        if (\trim($parsedValue) === 'null') {
                            $quoted ??= $this->quotedFields($stream, $offset, $delimiter);

                            if (!($quoted[$index] ?? false)) {
                                $parsedData[$key] = null;
                                continue;
                            }
                        }

                        if (isset($arrayKeys[$key])) {
                            if ($parsedValue === '') {
                                $parsedData[$key] = [];
                            } else {
                                // Try to decode as JSON first
                                $arrayValues = \json_decode($parsedValue, true);

                                // If JSON decode fails, fall back to comma-separated parsing
                                if (\json_last_error() !== JSON_ERROR_NONE) {
                                    // Remove empty strings from comma-separated parsing
                                    $arrayValues = \str_getcsv($parsedValue, ',', '"', '"');
                                    $arrayValues = \array_map(\trim(...), $arrayValues);
                                    $arrayValues = \array_filter($arrayValues, fn ($item) => $item !== '');
                                    $arrayValues = \array_values($arrayValues); // Re-index array
                                }

                                if (!\is_array($arrayValues)) {
                                    throw new \InvalidArgumentException("'$key' must be an array");
                                }

                                $parsedData[$key] = \array_map(fn ($item) => $this->parseValue($key, $item, $type), $arrayValues);
                            }
                            continue;
                        }

                        if ($parsedValue === '') {
                            $parsedData[$key] = isset($textKeys[$key]) ? '' : null;
                            continue;
                        }

                        $parsedData[$key] = $this->parseValue($key, $parsedValue, $type);
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->rejectRow($databaseType, $tablePayload, $data['$id'] ?? null, "Row $rowNumber: {$e->getMessage()}.");
                    continue;
                }

                $rowId = $parsedData['$id'] ?? 'unique()';
                $permissions = $parsedData['$permissions'] ?? [];

                unset($parsedData['$id'], $parsedData['$permissions']);

                $row = Appwrite::getRecord($databaseType, [
                    'id' => $rowId,
                    'table' => $tablePayload,
                    'data' => $parsedData,
                    'permissions' => $permissions
                ]);

                $buffer[] = $row;

                if (\count($buffer) === $batchSize) {
                    $this->callback($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->callback($buffer);
            }
        });
    }

    /**
     * @throws \InvalidArgumentException when the value is not of the column's type.
     */
    private function parseValue(string $key, mixed $value, ?string $type): mixed
    {
        switch ($type) {
            case Column::TYPE_INTEGER:
            case Column::TYPE_BIG_INT:
                if (\is_numeric($value)) {
                    $number = $value + 0;

                    // Spreadsheets can write whole numbers as 1.0 or 1e3.
                    if (\is_int($number) || (\floor($number) === $number && \abs($number) < 2 ** 63)) {
                        return (int) $number;
                    }
                }

                throw new \InvalidArgumentException("'$key' must be an integer");

            case Column::TYPE_FLOAT:
                if (\is_numeric($value)) {
                    return (float) $value;
                }

                throw new \InvalidArgumentException("'$key' must be a number");

            case Column::TYPE_BOOLEAN:
                $boolean = \is_bool($value) ? $value : \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolean !== null) {
                    return $boolean;
                }

                throw new \InvalidArgumentException("'$key' must be true or false");

            case Column::TYPE_POINT:
            case Column::TYPE_LINE:
            case Column::TYPE_POLYGON:
            case Column::TYPE_VECTOR:
            case Column::TYPE_OBJECT:
                $decoded = \json_decode((string) $value, true);
                if (\json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }

                throw new \InvalidArgumentException("'$key' must be JSON");

            default:
                return $value;
        }
    }

    /**
     * Report a row that is not imported, and count it as an error.
     *
     * @param array<string, mixed> $table
     */
    private function rejectRow(string $databaseType, array $table, ?string $rowId, string $message): void
    {
        $row = Appwrite::getRecord($databaseType, [
            'id' => $rowId ?? '',
            'table' => $table,
            'data' => [],
            'permissions' => [],
        ]);
        $row->setStatus(UtopiaResource::STATUS_ERROR, $message);
        $this->cache->update($row);

        // A malformed file would otherwise add an error for each of its rows.
        if (++$this->rejectedRows > self::MAX_ROW_ERRORS) {
            return;
        }

        $this->addError(new Exception(
            UtopiaResource::TYPE_ROW,
            Transfer::GROUP_DATABASES,
            resourceId: $rowId,
            message: $message,
            code: Exception::CODE_VALIDATION,
        ));
    }

    /**
     * Whether each field of the record at $offset was enclosed in quotes, which fgetcsv() does not tell.
     *
     * @param resource $stream
     * @return array<int, bool>
     */
    private function quotedFields($stream, int $offset, string $delimiter): array
    {
        $end = (int) \ftell($stream);
        \fseek($stream, $offset);
        $record = (string) \fread($stream, \max(1, $end - $offset));
        \fseek($stream, $end);

        $quoted = [];
        $position = 0;

        while (true) {
            // Like fgetcsv(), skip whitespace before an opening quote.
            $start = $position + \strspn($record, \str_replace($delimiter, '', " \t"), $position);
            $isQuoted = ($record[$start] ?? '') === '"';
            $quoted[] = $isQuoted;

            if ($isQuoted) {
                // Find the closing quote, stepping over escaped ("") ones.
                $position = $start + 1;
                while (($position = \strpos($record, '"', $position)) !== false && ($record[$position + 1] ?? '') === '"') {
                    $position += 2;
                }

                if ($position === false) {
                    break;
                }
            }

            $position = \strpos($record, $delimiter, $position);
            if ($position === false) {
                break;
            }

            $position++;
        }

        return $quoted;
    }

    /**
     * @throws \Exception
     */
    protected function exportGroupStorage(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
    protected function exportBuckets(int $batchSize): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
    private function exportFiles(int $batchSize): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
    private function exportFile(File $file): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
    protected function exportGroupFunctions(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
    protected function exportGroupMessaging(int $batchSize, array $resources): void
    {
        throw new \Exception('Not Implemented');
    }

    /**
     * @throws \Exception
     */
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

    /**
     * @param callable(resource $stream, string $delimiter): void $callback
     * @return void
     * @throws \Exception
     */
    private function withCsvStream(callable $callback): void
    {
        if (!$this->device->exists($this->filePath)) {
            return;
        }

        if (!$this->downloaded) {
            $this->downloadToLocal(
                $this->device,
                $this->filePath,
            );
        }

        $stream = \fopen($this->filePath, 'r');
        if (!$stream) {
            return;
        }

        $delimiter = $this->delimiter($stream);

        // Excel's "CSV UTF-8" starts with a byte order mark, which would become part of the first header.
        if (\fread($stream, 3) !== "\xEF\xBB\xBF") {
            \rewind($stream);
        }

        try {
            $callback($stream, $delimiter);
        } finally {
            \fclose($stream);
        }
    }

    /**
     * @throws \Exception
     */
    private function validateCSVHeaders(array $headers, array $columnTypes, array $requiredColumns): void
    {
        $internals = ['$id', '$permissions', '$createdAt', '$updatedAt'];
        $allKnown = \array_keys($columnTypes);

        // Only validate that required columns are present
        $missingRequired = [];
        foreach (\array_keys($requiredColumns) as $requiredColumn) {
            if (!\in_array($requiredColumn, $headers)) {
                $missingRequired[] = $requiredColumn;
            }
        }

        $messages = [];

        // If there are missing required columns, throw an exception
        if (!empty($missingRequired)) {
            $label = \count($missingRequired) === 1 ? 'Missing required column' : 'Missing required columns';
            $messages[] = "$label: '" . \implode("', '", $missingRequired) . "'";
        }
        if (!empty($missingRequired)) {
            throw new \Exception('CSV header validation failed: ' . \implode('. ', $messages), Exception::CODE_VALIDATION);
        }

        // If there are unknown columns but no missing required columns, just log a warning
        $unknown = \array_diff($headers, $allKnown, $internals);
        if (!empty($unknown)) {
            $label = \count($unknown) === 1 ? 'Unknown column' : 'Unknown columns';
            $messages[] = "$label: '" . \implode("', '", $unknown) . "' (will be ignored)";
        }
        if (!empty($unknown)) {
            $this->addWarning(new Warning(
                UtopiaResource::TYPE_ROW,
                Transfer::GROUP_DATABASES,
                \implode(', ', $messages),
                $this->resourceChildId ?? $this->resourceId
            ));
        }
    }

    /**
     * @return array{string, string}
     */
    private function getResourceIds(): array
    {
        if ($this->resourceChildId !== null) {
            return [$this->resourceId, $this->resourceChildId];
        }

        $resourceIds = \explode(':', $this->resourceId, 2);

        return [$resourceIds[0], $resourceIds[1] ?? ''];
    }

    /**
     * @throws \Exception
     */
    private function downloadToLocal(
        Device $device,
        string $filePath
    ): void {
        if ($this->downloaded
            || $device->getType() === DeviceType::Local
        ) {
            return;
        }

        try {
            $success = $device->copy(
                $filePath,
                $filePath,
                new Device\Local('/'),
            );
        } catch (\Exception $e) {
            $success = false;
        }

        if (!$success) {
            throw new \Exception(
                'Failed to transfer CSV file from device to local storage.',
                Exception::CODE_INTERNAL,
                $e ?? null
            );
        }

        $this->downloaded = true;
    }

    /**
     * @param resource $stream
     * @return string
     */
    private function delimiter($stream): string
    {
        /**
         * widely used options, from here -
         *
         * https://stackoverflow.com/a/15946087/6819340
         */
        $delimiters = [',', ';', "\t", '|'];

        $sampleLines = [];

        for ($i = 0; $i < 5 && !\feof($stream); $i++) {
            $line = \fgets($stream);
            if ($line === false) {
                break;
            }

            $line = \trim($line);

            // empty line, skip for sampling
            if (empty($line)) {
                $i--;
                continue;
            }

            $sampleLines[] = $line;
        }

        /**
         * reset to top again because we need to process
         * the same file later again if everything goes OK here!
         */
        \rewind($stream);

        if (empty($sampleLines)) {
            return ',';
        }

        $delimiterScores = [];

        foreach ($delimiters as $delimiter) {
            $columnCounts = [];
            $totalFields = 0;
            $usableFields = 0;

            foreach ($sampleLines as $line) {
                // delimiter doesn't exist
                if (!\str_contains($line, $delimiter)) {
                    $fields = [$line];
                } else {
                    $fields = \str_getcsv($line, $delimiter, '"', '"');
                }

                $fieldCount = \count($fields);
                $columnCounts[] = $fieldCount;
                $totalFields += $fieldCount;

                // Count fields that make some sense i.e.
                // longer than 1 char or single alphanumeric
                foreach ($fields as $field) {
                    $trimmed = \trim($field);
                    if (\strlen($trimmed) > 1) {
                        $usableFields++;
                    }
                }
            }

            $sampleCount = \count($columnCounts);
            $avgColumns = $totalFields / $sampleCount;

            // short-circuit
            // if the delimiter doesn't split anything
            if ($avgColumns <= 1) {
                $delimiterScores[$delimiter] = 0;
                continue;
            }

            // check consistency
            if ($sampleCount <= 1) {
                $consistencyScore = 1.0;
            } else {
                $variance = 0;
                foreach ($columnCounts as $count) {
                    $variance += \pow($count - $avgColumns, 2);
                }

                // oof, math!
                $stddev = \sqrt($variance / $sampleCount);
                $coefficientOfVariation = $stddev / $avgColumns;

                // lower variance = higher score
                $consistencyScore = 1.0 / (1.0 + $coefficientOfVariation * 2);
            }

            $qualityScore = $totalFields > 0 ? $usableFields / $totalFields : 0.0;

            $delimiterScores[$delimiter] = $consistencyScore * $qualityScore;
        }

        // sort as per score
        \arsort($delimiterScores);

        // get the first
        $bestDelimiter = \key($delimiterScores);

        return ($bestDelimiter && $delimiterScores[$bestDelimiter] > 0) ? $bestDelimiter : ',';
    }
}
