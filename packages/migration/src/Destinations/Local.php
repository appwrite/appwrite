<?php

namespace Utopia\Migration\Destinations;

use Utopia\Migration\Destination;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Storage\File;

/**
 * Local
 *
 * Used to export data to a local file system or for testing purposes.
 * Exports all data to a single JSON File.
 */
class Local extends Destination
{
    /**
     * @var array<string, array<string, array<string>>>
     */
    private array $data = [];

    protected string $path;

    public function __construct(string $path)
    {
        $this->path = $path;

        if (! \file_exists($this->path)) {
            mkdir($this->path, 0777, true);
            mkdir($this->path . '/files', 0777, true);
            mkdir($this->path . '/deployments', 0777, true);
        }
    }

    public static function getName(): string
    {
        return 'Local';
    }

    /**
     * @return array<string>
     */
    public static function getSupportedResources(): array
    {
        return [
            // Auth
            Resource::TYPE_USER,
            Resource::TYPE_TEAM,
            Resource::TYPE_MEMBERSHIP,
            Resource::TYPE_HASH,

            // Database
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX,
            Resource::TYPE_ROW,

            // legacy
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_COLLECTION,

            // Storage
            Resource::TYPE_BUCKET,
            Resource::TYPE_FILE,

            // Functions
            Resource::TYPE_FUNCTION,
            Resource::TYPE_DEPLOYMENT,
            Resource::TYPE_ENVIRONMENT_VARIABLE,

            // Messaging
            Resource::TYPE_PROVIDER,
            Resource::TYPE_TOPIC,
            Resource::TYPE_SUBSCRIBER,
            Resource::TYPE_MESSAGE,
        ];
    }

    /**
     * @throws \Exception
     */
    public function report(array $resources = [], array $resourceIds = []): array
    {
        $report = [];

        if (!\is_writable($this->path . '/backup.json')) {
            throw new \Exception('Unable to write to file: ' . $this->path, MigrationException::CODE_INTERNAL);
        }

        return $report;
    }

    /**
     * @throws \Exception
     */
    private function sync(): void
    {
        $json = \json_encode($this->data, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new \Exception(
                'Unable to encode data to JSON, Are you accidentally encoding binary data?',
                MigrationException::CODE_VALIDATION
            );
        }

        \file_put_contents($this->path . '/backup.json', $json);
    }

    /**
     * @param array<Resource> $resources
     * @param callable $callback
     * @throws \Exception
     */
    protected function import(array $resources, callable $callback): void
    {
        foreach ($resources as $resource) {
            switch ($resource->getName()) {
                case Resource::TYPE_DEPLOYMENT:
                    /** @var Deployment $resource */
                    if ($resource->getStart() === 0) {
                        $this->data[$resource->getGroup()][$resource->getName()][] = (string) \json_encode($resource);
                    }

                    file_put_contents($this->path . 'deployments/' . $resource->getId() . '.tar.gz', $resource->getData(), FILE_APPEND);
                    $resource->setData('');
                    break;
                case Resource::TYPE_FILE:
                    /** @var File $resource */
                    if (str_contains($resource->getFileName(), '/')) {
                        $folders = explode('/', $resource->getFileName());
                        $folderPath = $this->path.'/files';

                        foreach ($folders as $folder) {
                            $folderPath .= '/'.$folder;

                            if (! \file_exists($folderPath) && str_contains($folder, '.') === false) {
                                mkdir($folderPath, 0777, true);
                            }
                        }
                    }

                    if ($resource->getStart() === 0 && \file_exists($this->path.'/files/'.$resource->getFileName())) {
                        unlink($this->path.'/files/'.$resource->getFileName());
                    }

                    file_put_contents($this->path.'/files/'.$resource->getFileName(), $resource->getData(), FILE_APPEND);
                    $resource->setData('');
                    break;
                default:
                    $this->data[$resource->getGroup()][$resource->getName()][] = (string) \json_encode($resource);
                    break;
            }

            $resource->setStatus(Resource::STATUS_SUCCESS);
            $this->cache->update($resource);

            $this->sync();
        }

        $callback($resources);
    }
}
