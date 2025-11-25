<?php

namespace Appwrite\Payments\Provider;

use Utopia\Database\Document;

class Registry
{
    /**
     * @var array<string, class-string<Adapter>>
     */
    private array $map = [];

    /**
     * @var array<string, Adapter>
     */
    private array $cache = [];

    public function __construct()
    {
        // Map provider identifiers to adapter classes
        // e.g., $this->map['stripe'] = \Appwrite\Payments\Provider\StripeAdapter::class;
    }

    public function register(string $identifier, string $adapterClass): void
    {
        $this->map[$identifier] = $adapterClass;
    }

    public function get(string $identifier, array $config, Document $project, \Utopia\Database\Database $dbForPlatform, \Utopia\Database\Database $dbForProject): Adapter
    {
        $cacheKey = $identifier . ':' . $project->getId();
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        if (!isset($this->map[$identifier])) {
            throw new \RuntimeException('Unknown payments provider: ' . $identifier);
        }
        $class = $this->map[$identifier];
        /** @var Adapter $adapter */
        $adapter = new $class($config, $project, $dbForProject, $dbForPlatform);
        $this->cache[$cacheKey] = $adapter;
        return $adapter;
    }
}
