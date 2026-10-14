<?php

namespace Utopia\Migration\Sources;

use JsonMachine\Exception\JsonMachineException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\JsonDecoder\PassThruDecoder;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Migration\Exception;
use Utopia\Migration\Resource as UtopiaResource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;

class JSON extends Source
{
    private string $filePath;

    /**
     * Legacy format: `{databaseId}:{tableId}`.
     */
    private string $resourceId;

    private ?string $resourceChildId = null;

    private Device $device;

    /** @noinspection PhpPropertyOnlyWrittenInspection */
    private ?UtopiaDatabase $dbForProject;

    private bool $downloaded = false;

    public function __construct(
        string $resourceId,
        string $filePath,
        Device $device,
        ?UtopiaDatabase $dbForProject,
    ) {
        $this->device = $device;
        $this->filePath = $filePath;
        $this->resourceId = $resourceId;

        /* kept for composer check */
        $this->dbForProject = $dbForProject;
    }

    public static function fromResourceIds(
        string $databaseId,
        string $tableId,
        string $filePath,
        Device $device,
        ?UtopiaDatabase $dbForProject,
    ): self {
        $source = new self(
            resourceId: $databaseId,
            filePath: $filePath,
            device: $device,
            dbForProject: $dbForProject,
        );
        $source->resourceChildId = $tableId;

        return $source;
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

        $items = Items::fromFile($this->filePath, [
            'decoder' => new PassThruDecoder(),
        ]);

        $report[UtopiaResource::TYPE_ROW] = \iterator_count($items);

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
            if (UtopiaResource::isSupported(UtopiaResource::TYPE_ROW, $resources)) {
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
        [$databaseId, $tableId] = $this->getResourceIds();
        $database = new Database($databaseId, '');
        $table = new Table($database, '', $tableId);

        $this->withJsonItems(function ($items) use ($table, $batchSize) {
            $buffer = [];

            foreach ($items as $index => $item) {
                if (!\is_array($item)) {
                    throw new \Exception("JSON item at index $index is not an object.", Exception::CODE_VALIDATION);
                }

                $rowId = $item['$id'] ?? 'unique()';
                $permissions = [];
                if (\array_key_exists('$permissions', $item)) {
                    $permissions = $this->validatePermissions($item['$permissions']);
                }

                unset($item['$id'], $item['$permissions']);

                $row = new Row(
                    $rowId,
                    $table,
                    $item,
                    $permissions,
                );

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

    /**
     * @param callable(Items): void $callback
     * @throws \Exception|JsonMachineException
     */
    private function withJsonItems(callable $callback): void
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

        $items = Items::fromFile($this->filePath, [
            'decoder' => new ExtJsonDecoder(true),
        ]);

        $callback($items);
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
                'Failed to transfer JSON file from device to local storage.',
                Exception::CODE_INTERNAL,
                $e ?? null
            );
        }

        $this->downloaded = true;
    }

    /**
     * @return array<int, string>
     * @throws \Exception
     */
    private function validatePermissions(mixed $permissions): array
    {
        if (!\is_array($permissions)) {
            throw new \Exception('Invalid permissions format; expected an array of strings.', Exception::CODE_VALIDATION);
        }

        foreach ($permissions as $value) {
            if (!\is_string($value)) {
                throw new \Exception('Invalid permission value; expected string.', Exception::CODE_VALIDATION);
            }
        }

        return $permissions;
    }
}
