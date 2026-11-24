<?php

namespace Appwrite\Video;

use Utopia\Cache\Cache;
use Utopia\Database\Cache\Query;
use Utopia\Database\Cache\Region;
use Utopia\Database\Cache\Scope;

/**
 * The find() result cache behind streaming manifests.
 *
 * A manifest is rebuilt on every request from list queries that only change
 * when a rendition, a caption or one of their segments is written. Those lists
 * are served from here, and every other collection is left alone: both its
 * reads and its writes pass through as if no query cache were set.
 *
 * Two places use it and both are needed. The database factory registers it as
 * every project database's invalidation, so a write from the API or a worker
 * retires the cached lists. The manifest routes set it on their own handle, so
 * only their reads pay for the lookup.
 */
class QueryCache extends Query
{
    public const array COLLECTIONS = [
        'videosRenditions',
        'videosRenditionsSegments',
        'videosCaptions',
        'videosCaptionsSegments',
    ];

    /**
     * How long a list is served without a write retiring it. Bounds the damage
     * of a write this cache never hears about, such as a row changed in SQL.
     */
    private const int TTL = 600;

    /**
     * Cache keys of the collections left alone, which is all a write hands back.
     *
     * @var array<string, true>
     */
    private array $ignored = [];

    public function __construct(Cache $cache)
    {
        parent::__construct($cache);

        foreach (self::COLLECTIONS as $collection) {
            $this->setRegion($collection, new Region(ttl: self::TTL));
        }
    }

    #[\Override]
    public function getRegion(string $collection): Region
    {
        return \in_array($collection, self::COLLECTIONS, true)
            ? parent::getRegion($collection)
            : new Region(enabled: false);
    }

    #[\Override]
    public function getCollectionKey(Scope $scope, string $collection): string
    {
        $key = parent::getCollectionKey($scope, $collection);

        if (!\in_array($collection, self::COLLECTIONS, true)) {
            $this->ignored[$key] = true;
        }

        return $key;
    }

    #[\Override]
    public function blockCollection(string $key, string $token): void
    {
        if (!isset($this->ignored[$key])) {
            parent::blockCollection($key, $token);
        }
    }

    #[\Override]
    public function activateCollection(string $key, string $token, int $writerTimeout): void
    {
        if (!isset($this->ignored[$key])) {
            parent::activateCollection($key, $token, $writerTimeout);
        }
    }
}
