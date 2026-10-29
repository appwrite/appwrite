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
        }

        $this->withCsvStream(function ($stream, $delimiter) use ($columnTypes, $databaseType, $requiredColumns, $manyToManyKeys, $arrayKeys, $tablePayload, $batchSize) {
            $headers = \fgetcsv($stream, 0, $delimiter, '"', '"');

            if (!\is_array($headers) || \count($headers) === 0) {
                return;
            }

            $this->validateCSVHeaders($headers, $columnTypes, $requiredColumns);

            $buffer = [];

            while (($row = \fgetcsv($stream, 0, $delimiter, '"', '"')) !== false) {
                if (\count($row) !== \count($headers)) {
                    throw new \Exception('CSV row does not match the number of header columns.', Exception::CODE_VALIDATION);
                }

                $data = \array_combine($headers, $row);
                if ($data === false) {
                    continue;
                }

                $parsedData = $data;

                foreach ($data as $key => $value) {
                    $parsedValue = \trim($value);
                    $type = $columnTypes[$key] ?? null;

                    if (!isset($type) && $key !== '$permissions') {
                        // Skip unknown columns
                        continue;
                    }

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
                                throw new \Exception("Invalid array format for column '$key': $parsedValue", Exception::CODE_VALIDATION);
                            }

                            $parsedData[$key] = array_map(function ($item) use ($type) {
                                return match ($type) {
                                    Column::TYPE_INTEGER,Column::TYPE_BIG_INT => is_numeric($item) ? (int)$item : null,
                                    Column::TYPE_FLOAT => is_numeric($item) ? (float)$item : null,
                                    Column::TYPE_BOOLEAN => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                                    default => $item,
                                };
                            }, $arrayValues);
                        }
                        continue;
                    }

                    // Undo the quote CSV exports prefix to formula-like values
                    $unquoted = \ltrim($parsedValue, "'");
                    if ($unquoted !== $parsedValue && $unquoted !== '' && \str_contains("=+-@\t\r", $unquoted[0])) {
                        $parsedValue = \substr($parsedValue, 1);
                    }

                    /**
                     * Parsing logic for best compatibility with spec and 3rd party tools.
                     * - 'null' unquoted literal is converted to null.
                     * - missing strings stay empty strings for best compatibility.
                     * - missing numbers, booleans, and datetime's are converted to null.
                     * - other values are parsed as per their type.
                     */

                    $parsedData[$key] = match ($parsedValue) {
                        'null' => null, // 'null' string is converted to null
                        '' => match ($type) {
                            Column::TYPE_INTEGER,
                            Column::TYPE_BIG_INT,
                            Column::TYPE_FLOAT,
                            Column::TYPE_BOOLEAN,
                            Column::TYPE_DATETIME,
                            Column::TYPE_RELATIONSHIP => null, // primitive types default to null
                            default => '', // but empty string stays empty string for compatibility
                        },
                        default => match ($type) {
                            Column::TYPE_INTEGER, Column::TYPE_BIG_INT => \is_numeric($parsedValue) ? (int)$parsedValue : null,
                            Column::TYPE_FLOAT => \is_numeric($parsedValue) ? (float)$parsedValue : null,
                            Column::TYPE_BOOLEAN => \filter_var(
                                $parsedValue,
                                filter: FILTER_VALIDATE_BOOLEAN,
                                options: FILTER_NULL_ON_FAILURE
                            ),
                            Column::TYPE_POINT,
                            Column::TYPE_LINE,
                            Column::TYPE_POLYGON,
                            Column::TYPE_VECTOR,
                            Column::TYPE_OBJECT => \is_string($parsedValue) ? json_decode($parsedValue, true) : null,
                            default => $parsedValue,
                        },
                    };
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
