<?php

namespace Utopia\Tests\Unit\Adapters;

use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Storage\File;

class MockDestination extends Destination
{
    public array $data = [];

    public function getGroupData(string $group): array
    {
        return $this->data[$group] ?? [];
    }

    public function getResourceTypeData(string $group, string $resourceType): array
    {
        return array_keys($this->data[$group][$resourceType]) ?? [];
    }

    public function getResourceById(string $group, string $resourceType, string $resourceId): ?Resource
    {
        return $this->data[$group][$resourceType][$resourceId] ?? null;
    }

    public static function getName(): string
    {
        return 'MockDestination';
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
            Resource::TYPE_RULE,
        ];
    }

    public function import(array $resources, callable $callback): void
    {
        foreach ($resources as $resource) {
            /** @var Resource $resource */
            switch ($resource->getName()) {
                case 'Deployment':
                    /** @var Deployment $resource */
                    if ($resource->getStart() === 0) {
                        $this->data[$resource->getGroup()][$resource->getName()][$resource->getId()] = $resource;
                    }

                    // file_put_contents($this->path . 'deployments/' . $resource->getId() . '.tar.gz', $resource->getData(), FILE_APPEND);
                    break;
                case Resource::TYPE_FILE:
                    /** @var File $resource */
                    break;
            }

            if (!key_exists($resource->getGroup(), $this->data)) {
                $this->data[$resource->getGroup()] = [];
            }

            if (!key_exists($resource->getName(), $this->data[$resource->getGroup()])) {
                $this->data[$resource->getGroup()][$resource->getName()] = [];
            }

            $this->data[$resource->getGroup()][$resource->getName()][$resource->getId()] = $resource;
            $resource->setStatus(Resource::STATUS_SUCCESS);
            $this->cache->update($resource);
        }

        $callback($resources);
    }

    public function report(array $resources = [], array $resourceIds = []): array
    {
        return [];
    }
}
