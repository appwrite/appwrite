<?php

namespace Utopia\Tests\Unit\Adapters;

use Override;
use Utopia\Migration\Resource;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;

class MockSource extends Source
{
    private array $mockResources = [];

    private ?string $resourceChildId = null;

    #[Override]
    public function run(
        array $resources,
        callable $callback,
        string $rootResourceId = '',
        string $rootResourceType = '',
    ): void {
        $previousResourceChildId = $this->resourceChildId;

        if ($this->resourceChildId === null) {
            $this->resourceChildId = '';

            if (
                $rootResourceId !== ''
                && \array_key_exists($rootResourceType, Resource::DATABASE_TYPE_RESOURCE_MAP)
                && \str_contains($rootResourceId, ':')
            ) {
                [$rootResourceId, $this->resourceChildId] = \explode(':', $rootResourceId, 2);
            }
        }

        try {
            parent::run($resources, $callback, $rootResourceId, $rootResourceType);
        } finally {
            $this->resourceChildId = $previousResourceChildId;
        }
    }

    #[Override]
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
        $previousResourceChildId = $this->resourceChildId;
        $this->resourceChildId = $parentResourceId !== '' ? $resourceId : '';

        try {
            parent::runWithResourceSelector(
                $resources,
                $callback,
                $resourceId,
                $resourceInternalId,
                $resourceType,
                $parentResourceId,
                $parentResourceInternalId,
                $parentResourceType,
            );
        } finally {
            $this->resourceChildId = $previousResourceChildId;
        }
    }

    public function pushMockResource(Resource $resource): void
    {
        if (!array_key_exists($resource->getGroup(), $this->mockResources)) {
            $this->mockResources[$resource->getGroup()] = [];
        }

        if (!array_key_exists($resource->getName(), $this->mockResources[$resource->getGroup()])) {
            $this->mockResources[$resource->getGroup()][$resource->getName()] = [];
        }

        $this->mockResources[$resource->getGroup()][$resource->getName()][$resource->getId()] = $resource;
    }

    public function getMockResources(): array
    {
        return $this->mockResources;
    }

    public function getMockResourcesByType(string $group, string $type): array
    {
        return array_values($this->mockResources[$group][$type] ?? []);
    }

    public function getMockResourceById(string $group, string $type, string $id): ?Resource
    {
        return $this->mockResources[$group][$type][$id] ?? null;
    }

    public function clearMockResources(): void
    {
        $this->mockResources = [];
    }

    private function handleResourceTransfer(string $group, string $type): void
    {
        if (in_array($type, Transfer::ROOT_RESOURCES) && !empty($this->rootResourceId)) {
            $this->callback([$this->getMockResourceById($group, $type, $this->rootResourceId)]);
            return;
        }

        $entityType = Resource::DATABASE_TYPE_RESOURCE_MAP[$this->rootResourceType]['entity'] ?? null;
        if ($type === $entityType && $this->rootResourceId !== '' && $this->resourceChildId !== '') {
            $this->callback([$this->getMockResourceById($group, $type, $this->resourceChildId)]);
            return;
        }

        $this->callback($this->getMockResourcesByType($group, $type));
    }

    public static function getName(): string
    {
        return 'MockSource';
    }

    public static function getSupportedResources(): array
    {
        return [
            Resource::TYPE_COLUMN,
            Resource::TYPE_BUCKET,
            Resource::TYPE_TABLE,
            Resource::TYPE_DATABASE,
            Resource::TYPE_ROW,
            Resource::TYPE_FILE,
            Resource::TYPE_FUNCTION,
            Resource::TYPE_DEPLOYMENT,
            Resource::TYPE_SITE,
            Resource::TYPE_SITE_DEPLOYMENT,
            Resource::TYPE_SITE_VARIABLE,
            Resource::TYPE_HASH,
            Resource::TYPE_INDEX,
            Resource::TYPE_USER,
            Resource::TYPE_ENVIRONMENT_VARIABLE,
            Resource::TYPE_TEAM,
            Resource::TYPE_MEMBERSHIP,
            Resource::TYPE_OAUTH2_PROVIDER,
            Resource::TYPE_PLATFORM,
            Resource::TYPE_API_KEY,
            Resource::TYPE_SMTP,
            Resource::TYPE_PROJECT_VARIABLE,
            Resource::TYPE_WEBHOOK,
            Resource::TYPE_AUTH_METHODS,
            Resource::TYPE_POLICIES,
            Resource::TYPE_PROJECT_PROTOCOLS,
            Resource::TYPE_PROJECT_LABELS,
            Resource::TYPE_PROJECT_SERVICES,
            Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
            Resource::TYPE_PROVIDER,
            Resource::TYPE_TOPIC,
            Resource::TYPE_SUBSCRIBER,
            Resource::TYPE_MESSAGE,
            Resource::TYPE_BACKUP_POLICY,
            Resource::TYPE_RULE,

            // legacy
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_COLLECTION,
        ];
    }

    public function report(array $resources = [], array $resourceIds = []): array
    {
        return [];
    }

    /**
     * Export Auth Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupAuth(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_AUTH_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_AUTH, $resource);
        }
    }

    /**
     * Export Databases Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupDatabases(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_DATABASES_RESOURCES as $resource) {
            if (!Resource::isSupported($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_DATABASES, $resource);
        }
    }

    /**
     * Export Storage Group
     *
     * @param  int  $batchSize  Max 5
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupStorage(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_STORAGE_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_STORAGE, $resource);
        }
    }

    /**
     * Export Functions Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupFunctions(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_FUNCTIONS_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_FUNCTIONS, $resource);
        }
    }

    /**
     * Export Messaging Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupMessaging(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_MESSAGING_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_MESSAGING, $resource);
        }
    }

    /**
     * Export Sites Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupSites(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_SITES_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_SITES, $resource);
        }
    }

    /**
     * Export Integrations Group
     *
     * @param  int  $batchSize  Max 100
     * @param  string[]  $resources  Resources to export
     */
    protected function exportGroupIntegrations(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_INTEGRATIONS_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_INTEGRATIONS, $resource);
        }
    }

    protected function exportGroupBackups(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_BACKUPS_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_BACKUPS, $resource);
        }
    }

    protected function exportGroupProjects(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_PROJECTS_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_PROJECTS, $resource);
        }
    }

    protected function exportGroupDomains(int $batchSize, array $resources): void
    {
        foreach (Transfer::GROUP_DOMAINS_RESOURCES as $resource) {
            if (!\in_array($resource, $resources)) {
                continue;
            }

            $this->handleResourceTransfer(Transfer::GROUP_DOMAINS, $resource);
        }
    }
}
