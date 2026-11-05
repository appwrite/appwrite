<?php

namespace Utopia\Migration;

abstract class Source extends Target
{
    protected static int $defaultBatchSize = 100;

    /**
     * @var callable(array<Resource>): void $transferCallback
     */
    protected $transferCallback;

    /**
     * @var array<string, int|string>
     */
    public array $previousReport = [];

    public function supportsDatabaseStatus(): bool
    {
        return false;
    }

    public function getAuthBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getDatabasesBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getStorageBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getFunctionsBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getMessagingBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getSitesBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getIntegrationsBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getBackupsBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getProjectsBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    public function getDomainsBatchSize(): int
    {
        return static::$defaultBatchSize;
    }

    /**
     * @param array<Resource> $resources
     * @return void
     */
    public function callback(array $resources): void
    {
        ($this->transferCallback)($resources);
    }

    /**
     * Transfer Resources into destination
     *
     * @param array<string> $resources Resources to transfer
     * @param callable $callback Callback to run after transfer
     * @param string $rootResourceId Root resource ID, If enabled you can only transfer a single root resource
     */
    public function run(array $resources, callable $callback, string $rootResourceId = '', string $rootResourceType = ''): void
    {
        $previousRootResourceId = $this->rootResourceId;
        $previousRootResourceType = $this->rootResourceType;
        $this->rootResourceId = $rootResourceId;
        $this->rootResourceType = $rootResourceType;

        $this->transferCallback = function (array $returnedResources) use ($callback, $resources) {
            $prunedResources = [];
            foreach ($returnedResources as $resource) {
                /** @var Resource $resource */
                if (!in_array($resource->getName(), $resources)) {
                    $resource->setStatus(Resource::STATUS_SKIPPED);
                } else {
                    /**
                     * Rows and documents are counted in cache in an aggregated way (per status count)
                     * to prevent memory blowups. Destination updates already account for these, and
                     * adding them here causes double counting (source + destination).
                     */
                    if (\in_array($resource->getName(), [Resource::TYPE_ROW, Resource::TYPE_DOCUMENT], true)) {
                        continue;
                    }
                    $prunedResources[] = $resource;
                }
            }

            $callback($returnedResources);
            $this->cache->addAll($prunedResources);
        };

        try {
            $this->exportResources($resources);
        } finally {
            $this->rootResourceId = $previousRootResourceId;
            $this->rootResourceType = $previousRootResourceType;
        }
    }

    /**
     * Transfer resources using Appwrite's canonical resource relation fields.
     *
     * @param array<string> $resources Resources to transfer
     * @param callable $callback Callback to run after transfer
     */
    public function runWithResourceSelector(
        array $resources,
        callable $callback,
        string $resourceId,
        string $resourceInternalId,
        string $resourceType,
        string $parentResourceId,
        string $parentResourceInternalId,
        string $parentResourceType,
    ): void {
        $selector = new ResourceSelector(
            $resourceId,
            $resourceInternalId,
            $resourceType,
            $parentResourceId,
            $parentResourceInternalId,
            $parentResourceType,
        );

        $this->run($resources, $callback, $selector->getScopeId(), $selector->getScopeType());
    }

    /**
     * Export Resources
     *
     * @param array<string> $resources Resources to export
     */
    public function exportResources(array $resources): void
    {
        $groups = [];
        foreach ($resources as $resource) {
            $mapping = [
                Transfer::GROUP_AUTH => Transfer::GROUP_AUTH_RESOURCES,
                Transfer::GROUP_DATABASES => Transfer::GROUP_DATABASES_RESOURCES,
                Transfer::GROUP_STORAGE => Transfer::GROUP_STORAGE_RESOURCES,
                Transfer::GROUP_FUNCTIONS => Transfer::GROUP_FUNCTIONS_RESOURCES,
                Transfer::GROUP_MESSAGING => Transfer::GROUP_MESSAGING_RESOURCES,
                Transfer::GROUP_SITES => Transfer::GROUP_SITES_RESOURCES,
                Transfer::GROUP_INTEGRATIONS => Transfer::GROUP_INTEGRATIONS_RESOURCES,
                Transfer::GROUP_BACKUPS => Transfer::GROUP_BACKUPS_RESOURCES,
                Transfer::GROUP_PROJECTS => Transfer::GROUP_PROJECTS_RESOURCES,
                Transfer::GROUP_DOMAINS => Transfer::GROUP_DOMAINS_RESOURCES,
            ];

            foreach ($mapping as $group => $resources) {
                if (\in_array($resource, $resources, true)) {
                    $groups[$group][] = $resource;
                    break;
                }
            }
        }

        if (empty($groups)) {
            return;
        }

        foreach ($groups as $group => $resources) {
            switch ($group) {
                case Transfer::GROUP_AUTH:
                    $this->exportGroupAuth($this->getAuthBatchSize(), $resources);
                    break;
                case Transfer::GROUP_DATABASES:
                    $this->exportGroupDatabases($this->getDatabasesBatchSize(), $resources);
                    break;
                case Transfer::GROUP_STORAGE:
                    $this->exportGroupStorage($this->getStorageBatchSize(), $resources);
                    break;
                case Transfer::GROUP_FUNCTIONS:
                    $this->exportGroupFunctions($this->getFunctionsBatchSize(), $resources);
                    break;
                case Transfer::GROUP_MESSAGING:
                    $this->exportGroupMessaging($this->getMessagingBatchSize(), $resources);
                    break;
                case Transfer::GROUP_SITES:
                    $this->exportGroupSites($this->getSitesBatchSize(), $resources);
                    break;
                case Transfer::GROUP_INTEGRATIONS:
                    $this->exportGroupIntegrations($this->getIntegrationsBatchSize(), $resources);
                    break;
                case Transfer::GROUP_BACKUPS:
                    $this->exportGroupBackups($this->getBackupsBatchSize(), $resources);
                    break;
                case Transfer::GROUP_PROJECTS:
                    $this->exportGroupProjects($this->getProjectsBatchSize(), $resources);
                    break;
                case Transfer::GROUP_DOMAINS:
                    $this->exportGroupDomains($this->getDomainsBatchSize(), $resources);
                    break;
            }
        }
    }

    /**
     * Export Auth Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupAuth(int $batchSize, array $resources): void;

    /**
     * Export Databases Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupDatabases(int $batchSize, array $resources): void;

    /**
     * Export Storage Group
     *
     * @param int $batchSize Max 5
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupStorage(int $batchSize, array $resources): void;

    /**
     * Export Functions Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupFunctions(int $batchSize, array $resources): void;

    /**
     * Export Messaging Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupMessaging(int $batchSize, array $resources): void;

    /**
     * Export Sites Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupSites(int $batchSize, array $resources): void;

    /**
     * Export Integrations Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupIntegrations(int $batchSize, array $resources): void;

    /**
     * Export Backups Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupBackups(int $batchSize, array $resources): void;

    /**
     * Export Projects Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupProjects(int $batchSize, array $resources): void;

    /**
     * Export Domains Group
     *
     * @param int $batchSize
     * @param array<string> $resources Resources to export
     */
    abstract protected function exportGroupDomains(int $batchSize, array $resources): void;
}
