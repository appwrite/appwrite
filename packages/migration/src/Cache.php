<?php

namespace Utopia\Migration;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Sites\Deployment as SiteDeployment;
use Utopia\Migration\Resources\Storage\File;

/**
 * Cache stores a local version of all data copied over from the source, This can be used as reference point for
 * previous transfers and also help the destination to determine what needs to be updated, modified,
 * added or removed. It is also used for debugging and validation purposes.
 */
class Cache
{
    /**
     * @var array<string, array<string, Resource|string>> $cache
     */
    protected array $cache = [];

    public function __construct()
    {
        $this->cache = [];
    }

    /**
     * Get cache key from resource
     *
     * @param Resource $resource
     * @return string
    */
    public function resolveResourceCacheKey(Resource $resource): string
    {
        if (! $resource->getSequence()) {
            $resource->setSequence(uniqid());
        }

        $resourceName = $resource->getName();
        $keys = [];

        switch ($resourceName) {
            case Resource::TYPE_TABLE:
            case Resource::TYPE_COLLECTION:
                /** @var Table $resource */
                $keys[] = $resource->getDatabase()->getType();
                $keys[] = $resource->getDatabase()->getSequence();
                break;

            case Resource::TYPE_ROW:
            case Resource::TYPE_DOCUMENT:
            case Resource::TYPE_COLUMN:
            case Resource::TYPE_ATTRIBUTE:
            case Resource::TYPE_INDEX:
                /** @var Row|Column|Index $resource */
                $table = $resource->getTable();
                $keys[] = $table->getDatabase()->getSequence();
                $keys[] = $table->getSequence();
                break;

            case Resource::TYPE_FILE:
                /** @var File $resource */
                $keys[] = $resource->getBucket()->getSequence();
                break;

            case Resource::TYPE_DEPLOYMENT:
                /** @var Deployment $resource */
                $keys[] = $resource->getFunction()->getSequence();
                break;

            case Resource::TYPE_SITE_DEPLOYMENT:
                /** @var SiteDeployment $resource */
                $keys[] = $resource->getSite()->getSequence();
                break;

            default:
                break;
        }

        $keys[] = $resource->getSequence();

        return \implode('_', $keys);
    }

    /**
     * Add Resource
     *
     * Places the resource in the cache, in the cache backend this also gets assigned a unique ID.
     *
     */
    public function add(Resource $resource): void
    {
        $key = $this->resolveResourceCacheKey($resource);

        if ($resource->getName() == Resource::TYPE_ROW || $resource->getName() == Resource::TYPE_DOCUMENT) {
            $status = $resource->getStatus();

            $counter = $this->cache[$resource->getName()][$status] ?? 0;
            $counter = intval($counter) + 1;

            $this->cache[$resource->getName()][$status] = $counter . ''; // Transfer.php check is_string($resource)

            return;
        }

        if ($resource->getName() == Resource::TYPE_FILE || $resource->getName() == Resource::TYPE_DEPLOYMENT || $resource->getName() == Resource::TYPE_SITE_DEPLOYMENT) {
            /** @var File|Deployment|SiteDeployment $resource */
            $resource->setData(''); // Prevent Memory Leak
        }

        $this->cache[$resource->getName()][$key] = $resource;
    }

    /**
     * Add All Resources
     *
     * @param  array<Resource>  $resources
     * @return void
     */
    public function addAll(array $resources): void
    {
        foreach ($resources as $resource) {
            $this->add($resource);
        }
    }

    /**
     * Update Resource
     *
     * Updates the resource in the cache, if the resource does not exist in the cache an exception is thrown.
     * Use Add to add a new resource to the cache.
     *
     * @param Resource $resource
     * @return void
     */
    public function update(Resource $resource): void
    {
        /**
         * todo: call directly $this->add($resource);
         */

        $key = $this->resolveResourceCacheKey($resource);

        /**
         * if rows then updating the status counter only
         */
        if ($resource->getName() == Resource::TYPE_ROW || $resource->getName() == Resource::TYPE_DOCUMENT) {
            $this->add($resource);

            return;
        }

        if (! in_array($resource->getName(), $this->cache)) {
            $this->add($resource);
        }

        $this->cache[$resource->getName()][$key] = $resource;
    }

    /**
     * @param array<Resource> $resources
     * @return void
     */
    public function updateAll(array $resources): void
    {
        foreach ($resources as $resource) {
            $this->update($resource);
        }
    }

    /**
     * Remove Resource
     *
     * Removes the resource from the cache, if the resource does not exist in the cache an exception is thrown.
     *
     * @param Resource $resource
     * @return void
     * @throws \Exception
     */
    public function remove(Resource $resource): void
    {
        $key = $this->resolveResourceCacheKey($resource);
        if ($resource->getName() == Resource::TYPE_ROW || $resource->getName() == Resource::TYPE_DOCUMENT) {
            if (! isset($this->cache[$resource->getName()][$key])) {
                throw new \Exception('Resource does not exist in cache');
            }
        }
        if (! in_array($resource, $this->cache[$resource->getName()])) {
            throw new \Exception('Resource does not exist in cache');
        }

        unset($this->cache[$resource->getName()][$key]);
    }

    /**
     * Get Resources
     *
     * @param string|Resource $resource
     * @return array<Resource>
     */
    public function get(string|Resource $resource): array
    {
        if (is_string($resource)) {
            return $this->cache[$resource] ?? [];
        } else {
            return $this->cache[$resource->getName()] ?? [];
        }
    }

    /**
     * Get All Resources
     *
     * @return array<string, array<string, Resource|string>>
     */
    public function getAll(): array
    {
        return $this->cache;
    }

    /**
     * Wipe Cache
     *
     * Removes all resources from the cache.
     *
     * @return void
     */
    public function wipe(): void
    {
        $this->cache = [];
    }
}
