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

    private string $filesRoot;

    private string $deploymentsRoot;

    public function __construct(string $path)
    {
        $this->path = $path;

        if (! \file_exists($this->path)) {
            \mkdir($this->path, 0777, true);
        }
        if (! \file_exists($this->path . '/files')) {
            \mkdir($this->path . '/files', 0777, true);
        }
        if (! \file_exists($this->path . '/deployments')) {
            \mkdir($this->path . '/deployments', 0777, true);
        }

        // realpath() follows a symlinked files/ or deployments/ directory, which
        // would otherwise become the trusted root. Keep those roots only when
        // the resolved path is still inside the export directory.
        $exportRoot = \realpath($this->path);
        $filesRoot = \realpath($this->path . '/files');
        $deploymentsRoot = \realpath($this->path . '/deployments');

        if ($exportRoot === false || $filesRoot === false || $deploymentsRoot === false) {
            throw new \Exception('Unable to resolve export directories', MigrationException::CODE_INTERNAL);
        }

        if (! $this->isBeneathRoot($exportRoot, $filesRoot) || ! $this->isBeneathRoot($exportRoot, $deploymentsRoot)) {
            throw new \Exception('Unable to resolve export directories', MigrationException::CODE_INTERNAL);
        }

        $this->filesRoot = $filesRoot;
        $this->deploymentsRoot = $deploymentsRoot;
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
     * Resolve a relative name under a canonical export root, creating parent
     * directories as needed. Rejects names that leave the root or follow a symlink.
     *
     * @throws \Exception
     */
    private function confinedPath(string $root, string $name): string
    {
        if (
            $name === ''
            || \str_starts_with($name, '/')
            || \str_contains($name, '\\')
            || \str_contains($name, "\0")
        ) {
            throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
        }

        $segments = \explode('/', $name);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
            }
        }

        $current = $root;
        $last = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $next = $current . '/' . $segment;

            if (\is_link($next)) {
                throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
            }

            if ($index < $last) {
                if (! \file_exists($next)) {
                    if (! \mkdir($next, 0777) && ! \is_dir($next)) {
                        throw new \Exception('Unable to create export directory', MigrationException::CODE_INTERNAL);
                    }
                } elseif (! \is_dir($next)) {
                    throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
                }

                $resolved = \realpath($next);
                if ($resolved === false || ! $this->isBeneathRoot($root, $resolved)) {
                    throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
                }

                $current = $resolved;
                continue;
            }

            if (\file_exists($next)) {
                $resolved = \realpath($next);
                if ($resolved === false || ! $this->isBeneathRoot($root, $resolved)) {
                    throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
                }

                return $resolved;
            }

            return $next;
        }

        throw new \Exception('Invalid export path', MigrationException::CODE_VALIDATION);
    }

    private function isBeneathRoot(string $root, string $path): bool
    {
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    /**
     * @param array<Resource> $resources
     * @param callable $callback
     * @throws \Exception
     */
    protected function import(array $resources, callable $callback): void
    {
        foreach ($resources as $resource) {
            try {
                switch ($resource->getName()) {
                    case Resource::TYPE_DEPLOYMENT:
                        /** @var Deployment $resource */
                        if ($resource->getStart() === 0) {
                            $this->data[$resource->getGroup()][$resource->getName()][] = (string) \json_encode($resource);
                        }

                        $deploymentPath = $this->confinedPath(
                            $this->deploymentsRoot,
                            $resource->getId() . '.tar.gz'
                        );

                        $data = $resource->getData();
                        $written = \file_put_contents($deploymentPath, $data, FILE_APPEND);

                        if ($written === false || $written !== \strlen($data)) {
                            throw new MigrationException(
                                resourceName: $resource->getName(),
                                resourceGroup: $resource->getGroup(),
                                resourceId: $resource->getId(),
                                message: 'Unable to write export file',
                                code: MigrationException::CODE_INTERNAL,
                            );
                        }

                        $resource->setData('');
                        break;
                    case Resource::TYPE_FILE:
                        /** @var File $resource */
                        $filePath = $this->confinedPath($this->filesRoot, $resource->getFileName());

                        if ($resource->getStart() === 0 && \file_exists($filePath)) {
                            \unlink($filePath);
                        }

                        $data = $resource->getData();
                        $written = \file_put_contents($filePath, $data, FILE_APPEND);

                        if ($written === false || $written !== \strlen($data)) {
                            throw new MigrationException(
                                resourceName: $resource->getName(),
                                resourceGroup: $resource->getGroup(),
                                resourceId: $resource->getId(),
                                message: 'Unable to write export file',
                                code: MigrationException::CODE_INTERNAL,
                            );
                        }

                        $resource->setData('');
                        break;
                    default:
                        $this->data[$resource->getGroup()][$resource->getName()][] = (string) \json_encode($resource);
                        break;
                }

                $resource->setStatus(Resource::STATUS_SUCCESS);
            } catch (\Throwable $e) {
                $resource->setStatus(Resource::STATUS_ERROR, $e->getMessage());

                $this->addError(new MigrationException(
                    resourceName: $resource->getName(),
                    resourceGroup: $resource->getGroup(),
                    resourceId: $resource->getId(),
                    message: $e->getMessage(),
                    code: $e->getCode(),
                    previous: $e
                ));
            }

            $this->cache->update($resource);

            $this->sync();
        }

        $callback($resources);
    }
}
