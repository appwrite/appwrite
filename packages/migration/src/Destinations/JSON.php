<?php

namespace Utopia\Migration\Destinations;

use Exception;
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

class JSON extends Destination
{
    protected Device $deviceForFiles;
    protected string $resourceId;
    private ?string $resourceChildId = null;
    protected string $directory;
    protected string $outputFile;
    protected Local $local;

    protected array $allowedColumns = [];

    private bool $jsonStarted = false;
    private bool $jsonHasItems = false;

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws Exception
     */
    public function __construct(
        Device $deviceForFiles,
        string $resourceId,
        string $directory,
        string $filename,
        array $allowedColumns = [],
    ) {
        $this->deviceForFiles = $deviceForFiles;
        $this->resourceId = $resourceId;
        $this->directory = $directory;
        $this->outputFile = $this->sanitizeFilename($filename);

        /* local settings */
        $this->local = new Local(\sys_get_temp_dir() . '/json_export_' . uniqid());
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
    ): self {
        $destination = new self(
            deviceForFiles: $deviceForFiles,
            resourceId: $databaseId,
            directory: $directory,
            filename: $filename,
            allowedColumns: $allowedColumns,
        );
        $destination->resourceChildId = $tableId;

        return $destination;
    }

    public static function getName(): string
    {
        return 'JSON';
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
     * @throws Exception
     */
    protected function import(array $resources, callable $callback): void
    {
        $handle = null;
        $buffer = '';
        $bufferSize = 0;
        $bufferBytes = 1024 * 1024; // 1MB
        $log = $this->local->getRoot() . '/' . $this->outputFile . '.json';

        $openHandle = function () use (&$handle, $log): void {
            if (isset($handle)) {
                return;
            }
            $handle = \fopen($log, 'a');
            if ($handle === false) {
                throw new Exception("Failed to open file for writing: $log");
            }
        };

        $flushBuffer = function () use (&$handle, &$buffer, &$bufferSize, $openHandle): void {
            if ($buffer === '') {
                return;
            }
            $openHandle();
            if (\fwrite($handle, $buffer) === false) {
                throw new Exception('Failed to write JSON data to file.');
            }
            $buffer = '';
            $bufferSize = 0;
        };

        $append = function (string $chunk) use (&$buffer, &$bufferSize, $bufferBytes, $flushBuffer): void {
            $buffer .= $chunk;
            $bufferSize += \strlen($chunk);
            if ($bufferSize >= $bufferBytes) {
                $flushBuffer();
            }
        };

        try {
            if (!$this->jsonStarted) {
                $append('[');
                $this->jsonStarted = true;
            }

            foreach ($resources as $resource) {
                if (!($resource instanceof Row)) {
                    continue;
                }

                $jsonData = $this->resourceToJSONData($resource);
                try {
                    $json = \json_encode(
                        $jsonData,
                        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                } catch (Exception $e) {
                    $resource->setStatus(UtopiaResource::STATUS_ERROR, $e->getMessage());
                    $this->addError(new MigrationException(
                        resourceName: $resource->getName(),
                        resourceGroup: $resource->getGroup(),
                        resourceId: $resource->getId(),
                        message: $e->getMessage(),
                        code: MigrationException::CODE_VALIDATION,
                        previous: $e,
                    ));
                    continue;
                }

                if ($this->jsonHasItems) {
                    $append(',');
                }

                $append($json);
                $this->jsonHasItems = true;

                $resource->setStatus(UtopiaResource::STATUS_SUCCESS);
                if (isset($this->cache)) {
                    $this->cache->update($resource);
                }
            }

            $flushBuffer();
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
        }

        $callback($resources);
    }

    /**
     * @throws Exception
     */
    public function shutdown(): void
    {
        $filename = $this->outputFile . '.json';
        $sourcePath = $this->local->getPath($filename);
        $destPath = $this->deviceForFiles->getPath($this->directory . '/' . $filename);

        if (!$this->local->exists($sourcePath)) {
            throw new Exception("No data to export for resource: $this->resourceId");
        }

        $handle = null;
        try {
            $handle = \fopen($sourcePath, 'a');
            if ($handle === false) {
                throw new Exception("Failed to open file for writing: $sourcePath");
            }
            if (!$this->jsonStarted) {
                \fwrite($handle, '[');
                $this->jsonStarted = true;
            }
            \fwrite($handle, ']');
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
        }

        try {
            $result = $this->local->copy(
                $sourcePath,
                $destPath,
                $this->deviceForFiles,
                Transfer::STORAGE_MAX_CHUNK_SIZE
            );
            if ($result === false) {
                throw new Exception('Error transferring to ' . $this->deviceForFiles->getRoot() . '/' . $filename);
            }
            if (!$this->deviceForFiles->exists($destPath)) {
                throw new Exception('File not found on destination: ' . $destPath);
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
     * Helper to ensure a directory exists.
     * @throws Exception
     */
    protected function createDirectory(string $path): void
    {
        if (!\file_exists($path)) {
            if (!\mkdir($path, 0755, true)) {
                throw new Exception('Error creating directory: ' . $path);
            }
        }
    }

    /**
     * Sanitize a filename to make it filesystem-safe
     */
    protected function sanitizeFilename(string $filename): string
    {
        $sanitized = \preg_replace('/[:\\/<>"|*?]/', '_', $filename);
        $sanitized = \preg_replace('/[^\x20-\x7E]/', '_', $sanitized);
        $sanitized = \trim($sanitized);
        return empty($sanitized) ? 'export' : $sanitized;
    }

    /**
     * Convert a resource to JSON-compatible data
     */
    protected function resourceToJSONData(Row $resource): array
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
            $data[$key] = $this->convertValueToJSON($value);
        }

        return $data;
    }

    /**
     * Convert a single value to JSON-compatible format
     */
    protected function convertValueToJSON(mixed $value): mixed
    {
        if (\is_null($value) || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if (\is_array($value)) {
            return $this->convertArrayToJSON($value);
        }

        if (\is_object($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * Convert array to JSON format
     */
    protected function convertArrayToJSON(array $value): array
    {
        if (empty($value)) {
            return [];
        }

        return array_map(function ($item) {
            return $this->convertValueToJSON($item);
        }, $value);
    }
}
