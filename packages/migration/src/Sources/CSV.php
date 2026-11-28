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

        $schema = [
            '$permissions' => ['type' => '$permissions', 'array' => true, 'manyToMany' => false, 'format' => '', 'size' => 0, 'elements' => [], 'min' => null, 'max' => null],
        ];
        $requiredColumns = [];

        foreach ($columns as $column) {
            if (!($column instanceof \ArrayAccess || \is_array($column)) || !\is_string($column['key'] ?? null) || !\is_string($column['type'] ?? null)) {
                continue;
            }

            $key = $column['key'];
            $type = $column['type'];
            $relationSide = $column['side'] ?? '';
            $relationType = $column['relationType'] ?? '';

            if (
                $type === Column::TYPE_RELATIONSHIP &&
                $relationSide === RelationshipSide::Child->value
            ) {
                continue;
            }

            if ($column['required'] ?? false) {
                $requiredColumns[$key] = true;
            }

            $options = \is_array($column['formatOptions'] ?? null) ? $column['formatOptions'] : [];
            $format = $column['format'] ?? '';
            $size = $column['size'] ?? 0;
            $elements = $options['elements'] ?? [];
            $min = $options['min'] ?? null;
            $max = $options['max'] ?? null;

            $schema[$key] = [
                'type' => $type,
                'array' => ($column['array'] ?? false) && $type !== Column::TYPE_RELATIONSHIP,
                'manyToMany' => $type === Column::TYPE_RELATIONSHIP
                    && $relationType === RelationshipType::ManyToMany->value
                    && $relationSide === RelationshipSide::Parent->value,
                'format' => \is_string($format) ? $format : '',
                'size' => \is_int($size) ? $size : 0,
                'elements' => \is_array($elements) ? $elements : [],
                'min' => \is_int($min) || \is_float($min) ? $min : null,
                'max' => \is_int($max) || \is_float($max) ? $max : null,
            ];
        }

        $this->withCsvStream(function ($stream, $delimiter) use ($schema, $databaseType, $requiredColumns, $tablePayload, $batchSize) {
            $headers = $this->readHeaders($stream, $delimiter);
            if ($headers === null) {
                return;
            }

            $this->validateCSVHeaders($headers, \array_diff_key($schema, ['$permissions' => true]), $requiredColumns);

            // Check every row before any is written, so one bad row cannot leave the table half imported
            foreach ($this->readRows($stream, $delimiter, $headers, $schema) as $_) {
            }

            \rewind($stream);
            $this->readHeaders($stream, $delimiter);

            $buffer = [];

            foreach ($this->readRows($stream, $delimiter, $headers, $schema) as $parsedData) {
                $rowId = $parsedData['$id'] ?? 'unique()';
                $permissions = $parsedData['$permissions'] ?? [];

                unset($parsedData['$id'], $parsedData['$permissions']);

                $buffer[] = Appwrite::getRecord($databaseType, [
                    'id' => $rowId,
                    'table' => $tablePayload,
                    'data' => $parsedData,
                    'permissions' => $permissions
                ]);

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
     * @return array<string>|null
     */
    private function readHeaders(mixed $stream, string $delimiter): ?array
    {
        $cells = $this->readLine($stream, $delimiter);
        if ($cells === null) {
            return null;
        }

        $headers = \array_map(static fn (array $cell) => $cell[0], $cells);

        // Excel's "CSV UTF-8" starts with a byte order mark
        if (\str_starts_with($headers[0], "\xEF\xBB\xBF")) {
            $headers[0] = \substr($headers[0], 3);
        }

        return $headers;
    }

    /**
     * Parse the rows after the header, throwing on the first value its column cannot hold.
     *
     * @param array<string> $headers
     * @param array<string, array{type: string, array: bool, manyToMany: bool, format: string, size: int, elements: array<mixed>, min: int|float|null, max: int|float|null}> $schema
     * @return \Generator<array<string, mixed>>
     * @throws \Exception
     */
    private function readRows(mixed $stream, string $delimiter, array $headers, array $schema): \Generator
    {
        $line = 1;

        while (($cells = $this->readLine($stream, $delimiter)) !== null) {
            $line++;

            if (\count($cells) !== \count($headers)) {
                throw new \Exception("CSV row does not match the number of header columns: row $line.", Exception::CODE_VALIDATION);
            }

            $parsedData = [];

            foreach ($headers as $index => $key) {
                [$value, $quoted] = $cells[$index];

                if (!\mb_check_encoding($value, 'UTF-8')) {
                    throw new \Exception("CSV row $line is not valid UTF-8.", Exception::CODE_VALIDATION);
                }

                $column = $schema[$key] ?? null;

                if ($column === null) {
                    // Internal columns pass through, unknown columns are skipped
                    if (\in_array($key, ['$id', '$createdAt', '$updatedAt'], true)) {
                        $parsedData[$key] = $value;
                    }
                    continue;
                }

                $parsedData[$key] = $this->parseCell($value, $quoted, $column, $key, $line);
            }

            yield $parsedData;
        }
    }

    /**
     * Read one CSV record, which may span lines inside quotes.
     *
     * @return array<array{string, bool}>|null cells as [value, quoted]
     */
    private function readLine(mixed $stream, string $delimiter): ?array
    {
        do {
            $cells = $this->readRecord($stream, $delimiter);
        } while ($cells === []);

        return $cells;
    }

    /**
     * @return array<array{string, bool}>|null cells as [value, quoted], empty for a blank line
     */
    private function readRecord(mixed $stream, string $delimiter): ?array
    {
        if (!\is_resource($stream)) {
            return null;
        }

        $line = \fgets($stream);
        if ($line === false) {
            return null;
        }

        $cells = [];
        $value = '';
        $quoted = false;
        $inQuotes = false;
        $position = 0;

        while (true) {
            $length = \strlen($line);

            while ($position < $length) {
                $char = $line[$position++];

                if ($inQuotes) {
                    if ($char !== '"') {
                        $value .= $char;
                    } elseif ($position < $length && $line[$position] === '"') {
                        $value .= '"';
                        $position++;
                    } else {
                        $inQuotes = false;
                    }
                    continue;
                }

                if ($char === $delimiter) {
                    $cells[] = [$value, $quoted];
                    $value = '';
                    $quoted = false;
                } elseif ($char === '"' && !$quoted && \trim($value) === '') {
                    // Whitespace before the opening quote is not part of the value
                    $value = '';
                    $quoted = true;
                    $inQuotes = true;
                } elseif ($char === "\n" || $char === "\r") {
                    break;
                } else {
                    $value .= $char;
                }
            }

            if (!$inQuotes) {
                break;
            }

            $next = \fgets($stream);
            if ($next === false) {
                break;
            }

            $line = $next;
            $position = 0;
        }

        $cells[] = [$value, $quoted];

        // Skip blank lines
        if (\count($cells) === 1 && !$quoted && \trim($value) === '') {
            return [];
        }

        return $cells;
    }

    /**
     * Parsing logic for best compatibility with spec and 3rd party tools.
     * - an unquoted 'null' is null, a quoted "null" is the string.
     * - unquoted values are trimmed, quoted values are kept as written.
     * - empty strings stay empty strings for plain text columns, and are null for every other type.
     * - other values must parse for their column, or the import fails with the row number.
     *
     * @param array{type: string, array: bool, manyToMany: bool, format: string, size: int, elements: array<mixed>, min: int|float|null, max: int|float|null} $column
     * @throws \Exception
     */
    private function parseCell(string $value, bool $quoted, array $column, string $key, int $line): mixed
    {
        if (!$quoted) {
            $value = \trim($value);

            if ($value === 'null') {
                return null;
            }
        }

        if ($column['manyToMany']) {
            // Split on commas, trim whitespace, drop empty tokens, and reindex
            $ids = \array_map(\trim(...), \explode(',', $value));
            return \array_values(\array_filter($ids, static fn ($id) => $id !== ''));
        }

        if ($column['array']) {
            if (\trim($value) === '') {
                return [];
            }

            // Try to decode as JSON first, fall back to comma-separated values
            $items = \json_decode($value, true);
            if (\json_last_error() !== JSON_ERROR_NONE) {
                $items = \array_map(\trim(...), \str_getcsv($value, ',', '"', '"'));
                $items = \array_values(\array_filter($items, static fn ($item) => $item !== ''));
            }

            if (!\is_array($items) || !\array_is_list($items)) {
                throw new \Exception("CSV row $line: invalid array for column '$key': $value", Exception::CODE_VALIDATION);
            }

            return \array_map(
                fn ($item) => $this->parseValue(\is_scalar($item) ? \trim((string) $item) : null, $column, $key, $line),
                $items
            );
        }

        // Undo the quote CSV exports prefix to formula-like values
        $unquoted = \ltrim($value, "'");
        if ($unquoted !== $value && $unquoted !== '' && \str_contains("=+-@\t\r", $unquoted[0])) {
            $value = \substr($value, 1);
        }

        if ($value === '') {
            $plain = \in_array($column['type'], [Column::TYPE_STRING, Column::TYPE_VARCHAR, Column::TYPE_TEXT, Column::TYPE_MEDIUMTEXT, Column::TYPE_LONGTEXT], true);
            return $plain && $column['format'] === '' ? '' : null;
        }

        return $this->parseValue($value, $column, $key, $line);
    }

    /**
     * Parse a non-empty value for its column type, rejecting values the column cannot hold
     * instead of storing null or a truncated cast.
     *
     * @param array{type: string, array: bool, manyToMany: bool, format: string, size: int, elements: array<mixed>, min: int|float|null, max: int|float|null} $column
     * @throws \Exception
     */
    private function parseValue(?string $value, array $column, string $key, int $line): mixed
    {
        $type = $column['type'];

        $parsed = $value === null ? null : match ($type) {
            Column::TYPE_INTEGER, Column::TYPE_BIG_INT => \filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            Column::TYPE_FLOAT => \is_numeric($value) ? (float) $value : null,
            Column::TYPE_BOOLEAN => \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            Column::TYPE_DATETIME => $this->isDatetime($value) ? $value : null,
            Column::TYPE_POINT,
            Column::TYPE_LINE,
            Column::TYPE_POLYGON,
            Column::TYPE_VECTOR,
            Column::TYPE_OBJECT => \json_decode($value, true),
            default => $this->isFormatted($value, $column) ? $value : null,
        };

        if (\is_int($parsed) || \is_float($parsed)) {
            if (($column['min'] !== null && $parsed < $column['min']) || ($column['max'] !== null && $parsed > $column['max'])) {
                $parsed = null;
            }
        }

        if (\is_string($parsed) && $column['size'] > 0 && \mb_strlen($parsed) > $column['size']) {
            throw new \Exception("CSV row $line: value for column '$key' is longer than {$column['size']} characters.", Exception::CODE_VALIDATION);
        }

        if ($parsed === null) {
            throw new \Exception("CSV row $line: invalid $type value for column '$key': $value", Exception::CODE_VALIDATION);
        }

        return $parsed;
    }

    /**
     * Absolute dates only: relative words like 'yesterday' and out-of-range dates like 31.02 are rejected.
     */
    private function isDatetime(string $value): bool
    {
        $parsed = \date_parse($value);

        return $parsed['error_count'] === 0
            && $parsed['warning_count'] === 0
            && !isset($parsed['relative'])
            && $parsed['year'] !== false
            && $parsed['month'] !== false
            && $parsed['day'] !== false;
    }

    /**
     * @param array{type: string, array: bool, manyToMany: bool, format: string, size: int, elements: array<mixed>, min: int|float|null, max: int|float|null} $column
     */
    private function isFormatted(string $value, array $column): bool
    {
        return match ($column['format']) {
            'enum' => \in_array($value, $column['elements'], true),
            'email' => \filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => \filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => \filter_var($value, FILTER_VALIDATE_IP) !== false,
            default => true,
        };
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
