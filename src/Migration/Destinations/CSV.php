<?php

namespace Utopia\Migration\Destinations;

use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Migration\Destination;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource as UtopiaResource;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Transfer;
use Utopia\Migration\Warning;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;

class CSV extends Destination
{
    protected Device $deviceForFiles;
    protected string $resourceId;
    private ?string $resourceChildId = null;
    protected string $directory;
    protected string $outputFile;
    protected Local $local;

    protected array $allowedColumns = [];

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws \Exception
     */
    public function __construct(
        Device $deviceForFiles,
        string $resourceId,
        string $directory,
        string $filename,
        array $allowedColumns = [],
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '"',
        private readonly bool $includeHeaders = true,
    ) {
        $this->deviceForFiles = $deviceForFiles;
        $this->resourceId = $resourceId;
        $this->directory = $directory;
        $this->outputFile = $this->sanitizeFilename($filename);
        $this->local = new Local(\sys_get_temp_dir() . '/csv_export_' . uniqid());
        $this->createDirectory($this->local->getRoot());

        foreach ($allowedColumns as $attribute) {
            $this->allowedColumns[$attribute] = true;
        }
    }

    public static function fromResourceIds(
        Device $deviceForFiles,
        string $databaseId,
        string $tableId,
        string $directory,
        string $filename,
        array $allowedColumns = [],
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '"',
        bool $includeHeaders = true,
    ): self {
        $destination = new self(
            deviceForFiles: $deviceForFiles,
            resourceId: $databaseId,
            directory: $directory,
            filename: $filename,
            allowedColumns: $allowedColumns,
            delimiter: $delimiter,
            enclosure: $enclosure,
            escape: $escape,
            includeHeaders: $includeHeaders,
        );
        $destination->resourceChildId = $tableId;

        return $destination;
    }

    public static function getName(): string
    {
        return 'CSV';
    }

    public static function getSupportedResources(): array
    {
        return [
            UtopiaResource::TYPE_ROW,
        ];
    }

    public function report(array $resources = [], array $resourceIds = []): array
    {
        return [];
    }

    /**
     * @param array<Row> $resources
     * @throws \JsonException
     * @throws \Exception
     */
    protected function import(array $resources, callable $callback): void
    {
        $handle = null; // file handle
        $buffer = ['lines' => [], 'size' => 0];  // Buffer for batching writes
        $bufferBytes = 1024 * 1024; // 1MB
        $log = $this->local->getRoot() . '/' . $this->outputFile . '.csv';

        $flushBuffer = function () use ($log, &$handle, &$buffer) {
            if (empty($buffer['lines'])) {
                return;
            }
            try {
                if (!isset($handle)) {
                    $handle = \fopen($log, 'a');
                    if ($handle === false) {
                        throw new \Exception("Failed to open file for writing: $log", MigrationException::CODE_INTERNAL);
                    }
                }

                foreach ($buffer['lines'] as $line) {
                    if (!$this->writeCSVLine($handle, $line)) {
                        throw new \Exception("Failed to write CSV line to file: $log", MigrationException::CODE_INTERNAL);
                    }
                }

                $buffer = [
                    'lines' => [],
                    'size' => 0
                ];
            } catch (\Exception $e) {
                // Close handle on error
                if (isset($handle)) {
                    \fclose($handle);
                    unset($handle);
                }
                throw $e;
            }
        };

        try {
            foreach ($resources as $resource) {
                if (!($resource instanceof Row)) {
                    continue;
                }

                $csvData = $this->resourceToCSVData($resource);

                // Write headers if this is the first row of the file
                if (!isset($csvHeader) && $this->includeHeaders) {
                    $headers = \array_keys($csvData);
                    $buffer['lines'][] = $headers;
                    $buffer['size'] += \strlen(\implode($this->delimiter, $headers)) + 2; // Approximate size
                    $csvHeader = true;
                }

                $dataValues = \array_values($csvData);
                $buffer['lines'][] = $dataValues;
                $buffer['size'] += \strlen(\implode($this->delimiter, $dataValues)) + 2; // Approximate size

                if ($buffer['size'] >= $bufferBytes) {
                    $flushBuffer();
                }

                $resource->setStatus(UtopiaResource::STATUS_SUCCESS);
                if (isset($this->cache)) {
                    $this->cache->update($resource);
                }
            }

            // Flush any remaining buffered lines
            if (!empty($buffer['lines'])) {
                $flushBuffer();
            }
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
        }

        $callback($resources);
    }

    /**
     * @throws \Exception
     */
    public function shutdown(): void
    {
        $filename = $this->outputFile . '.csv';
        $sourcePath = $this->local->getPath($filename);
        $destPath = $this->deviceForFiles->getPath($this->directory . '/' . $filename);

        if (!$this->local->exists($sourcePath)) {
            throw new \Exception("No data to export for resource: $this->resourceId", MigrationException::CODE_NOT_FOUND);
        }

        try {
            $result = $this->local->copy(
                $sourcePath,
                $destPath,
                $this->deviceForFiles,
                Transfer::STORAGE_MAX_CHUNK_SIZE
            );
            if ($result === false) {
                throw new \Exception(
                    'Error transferring to ' . $this->deviceForFiles->getRoot() . '/' . $filename,
                    MigrationException::CODE_INTERNAL
                );
            }
            if (!$this->deviceForFiles->exists($destPath)) {
                throw new \Exception('File not found on destination: ' . $destPath, MigrationException::CODE_NOT_FOUND);
            }
        } finally {
            // Clean up the temporary directory
            if (!$this->local->deletePath('') || $this->local->exists($this->local->getRoot())) {
                $this->addWarning(new Warning(
                    UtopiaResource::TYPE_ROW,
                    Transfer::GROUP_DATABASES,
                    'Error cleaning up: ' . $this->local->getRoot(),
                    $this->resourceChildId ?? $this->resourceId
                ));
            }
        }
    }

    /**
     * Write a CSV line with RFC 4180 compliant escaping (double-quote method)
     *
     * @param resource $handle
     * @param array $fields
     * @return bool
     */
    protected function writeCSVLine($handle, array $fields): bool
    {
        $parts = [];

        foreach ($fields as $field) {
            $text = (string) $field;
            if (\strpbrk($text, $this->delimiter . "\n\r" . $this->enclosure) !== false) {
                $parts[] = $this->enclosure . \str_replace($this->enclosure, $this->enclosure . $this->enclosure, $text) . $this->enclosure;
            } else {
                $parts[] = $text;
            }
        }

        return \fwrite($handle, \implode($this->delimiter, $parts) . "\n") !== false;
    }

    /**
     * Helper to ensure a directory exists.
     * @throws \Exception
     */
    protected function createDirectory(string $path): void
    {
        if (!\file_exists($path)) {
            if (!\mkdir($path, 0755, true)) {
                throw new \Exception('Error creating directory: ' . $path, MigrationException::CODE_INTERNAL);
            }
        }
    }

    /**
     * Sanitize a filename to make it filesystem-safe
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Replace problematic characters with underscores
        $sanitized = \preg_replace('/[:\\/<>"|*?]/', '_', $filename);
        $sanitized = \preg_replace('/[^\x20-\x7E]/', '_', $sanitized);
        $sanitized = \trim($sanitized);
        return empty($sanitized) ? 'export' : $sanitized;
    }

    /**
     * Convert a resource to CSV-compatible data
     */
    protected function resourceToCSVData(Row $resource): array
    {
        $rowData = $resource->getData();

        $data = [
            '$id' => $resource->getId(),
            '$permissions' => $resource->getPermissions(),
            '$createdAt' => $rowData['$createdAt'] ?? '',
            '$updatedAt' => $rowData['$updatedAt'] ?? '',
        ];

        unset(
            $rowData['$createdAt'],
            $rowData['$updatedAt'],
        );

        // Add all attributes if no filter specified, otherwise only allowed ones
        if (empty($this->allowedColumns)) {
            $data = \array_merge($data, $rowData);
        } else {
            foreach ($rowData as $key => $value) {
                if (isset($this->allowedColumns[$key])) {
                    $data[$key] = $value;
                }
            }
        }

        foreach ($data as $key => $value) {
            $data[$key] = $this->convertValueToCSV($value);
        }

        return $data;
    }

    /**
     * Convert a single value to CSV-compatible format
     */
    protected function convertValueToCSV(mixed $value): string
    {
        if (\is_null($value)) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_array($value)) {
            return $this->convertArrayToCSV($value);
        }
        if (\is_object($value)) {
            return $this->convertObjectToCSV($value);
        }
        return (string)$value;
    }

    /**
     * Convert array to CSV format
     */
    protected function convertArrayToCSV(array $value): string
    {
        if (empty($value)) {
            return '';
        }
        if (isset($value['$id'])) {
            return $value['$id'];
        }
        return \json_encode($value);
    }

    /**
     * Convert object to CSV format
     */
    protected function convertObjectToCSV($value): string
    {
        if ($value instanceof Row) {
            return $value->getId();
        }
        return \json_encode($value);
    }
}
