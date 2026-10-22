<?php

namespace Utopia\Migration;

class Transfer
{
    public const GROUP_GENERAL = 'general';

    public const GROUP_AUTH = 'auth';

    public const GROUP_STORAGE = 'storage';

    public const GROUP_FUNCTIONS = 'functions';

    public const GROUP_SITES = 'sites';

    // separating databases and tablesdb out for easier separation in extract services
    // migration can use group_databases for mentioning all resources but when mentioning specific resources go with specific type databases
    public const GROUP_DATABASES = 'databases';
    public const GROUP_DATABASES_TABLES_DB = 'tablesdb';
    public const GROUP_DATABASES_DOCUMENTS_DB = 'documentsdb';

    public const GROUP_DATABASES_VECTOR_DB = 'vectorsdb';

    public const GROUP_INTEGRATIONS = 'integrations';

    public const GROUP_MESSAGING = 'messaging';

    public const GROUP_BACKUPS = 'backups';

    public const GROUP_PROJECTS = 'projects';

    public const GROUP_DOMAINS = 'domains';

    public const GROUP_AUTH_RESOURCES = [
        Resource::TYPE_USER,
        Resource::TYPE_TEAM,
        Resource::TYPE_MEMBERSHIP,
        Resource::TYPE_HASH,
        Resource::TYPE_AUTH_METHODS,
        Resource::TYPE_POLICIES,
        Resource::TYPE_OAUTH2_PROVIDER,
    ];

    public const GROUP_STORAGE_RESOURCES = [
        Resource::TYPE_FILE,
        Resource::TYPE_BUCKET
    ];

    public const GROUP_FUNCTIONS_RESOURCES = [
        Resource::TYPE_FUNCTION,
        Resource::TYPE_ENVIRONMENT_VARIABLE,
        Resource::TYPE_DEPLOYMENT
    ];

    public const GROUP_SITES_RESOURCES = [
        Resource::TYPE_SITE,
        Resource::TYPE_SITE_VARIABLE,
        Resource::TYPE_SITE_DEPLOYMENT
    ];

    public const GROUP_TABLESDB_RESOURCES = [
        Resource::TYPE_DATABASE,
        Resource::TYPE_TABLE,
        Resource::TYPE_INDEX,
        Resource::TYPE_COLUMN,
        Resource::TYPE_ROW,
    ];

    public const GROUP_INTEGRATIONS_RESOURCES = [
        Resource::TYPE_PLATFORM,
        Resource::TYPE_API_KEY,
        Resource::TYPE_WEBHOOK,
        Resource::TYPE_SMTP,
    ];
    public const GROUP_DOCUMENTSDB_RESOURCES = [
        Resource::TYPE_DATABASE_DOCUMENTSDB,
        Resource::TYPE_COLLECTION,
        Resource::TYPE_INDEX,
        Resource::TYPE_DOCUMENT
    ];

    public const GROUP_VECTORSDB_RESOURCES = [
        Resource::TYPE_DATABASE_VECTORSDB,
        Resource::TYPE_COLLECTION,
        Resource::TYPE_ATTRIBUTE,
        Resource::TYPE_INDEX,
        Resource::TYPE_DOCUMENT
    ];

    public const GROUP_DATABASES_RESOURCES = [
        Resource::TYPE_DATABASE,
        Resource::TYPE_DATABASE_DOCUMENTSDB,
        Resource::TYPE_DATABASE_VECTORSDB,
        Resource::TYPE_TABLE,
        Resource::TYPE_INDEX,
        Resource::TYPE_COLUMN,
        Resource::TYPE_ROW,
        Resource::TYPE_DOCUMENT,
        Resource::TYPE_COLLECTION,
        Resource::TYPE_ATTRIBUTE
    ];

    public const GROUP_PROJECTS_RESOURCES = [
        Resource::TYPE_PROJECT_VARIABLE,
        Resource::TYPE_PROJECT_PROTOCOLS,
        Resource::TYPE_PROJECT_LABELS,
        Resource::TYPE_PROJECT_SERVICES,
        Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
        Resource::TYPE_PROJECT_ENVIRONMENT,
    ];

    public const GROUP_BACKUPS_RESOURCES = [
        Resource::TYPE_BACKUP_POLICY,
    ];

    public const GROUP_DOMAINS_RESOURCES = [
        Resource::TYPE_RULE,
    ];

    public const GROUP_MESSAGING_RESOURCES = [
        Resource::TYPE_PROVIDER,
        Resource::TYPE_TOPIC,
        Resource::TYPE_SUBSCRIBER,
        Resource::TYPE_MESSAGE,
    ];

    public const ALL_PUBLIC_RESOURCES = [
        Resource::TYPE_USER,
        Resource::TYPE_TEAM,
        Resource::TYPE_MEMBERSHIP,
        Resource::TYPE_AUTH_METHODS,
        Resource::TYPE_POLICIES,
        Resource::TYPE_OAUTH2_PROVIDER,
        Resource::TYPE_FILE,
        Resource::TYPE_BUCKET,
        Resource::TYPE_FUNCTION,
        Resource::TYPE_ENVIRONMENT_VARIABLE,
        Resource::TYPE_DEPLOYMENT,
        Resource::TYPE_SITE,
        Resource::TYPE_SITE_VARIABLE,
        Resource::TYPE_SITE_DEPLOYMENT,
        Resource::TYPE_DATABASE,
        Resource::TYPE_TABLE,
        Resource::TYPE_INDEX,
        Resource::TYPE_COLUMN,
        Resource::TYPE_ROW,
        Resource::TYPE_PROVIDER,
        Resource::TYPE_TOPIC,
        Resource::TYPE_SUBSCRIBER,
        Resource::TYPE_MESSAGE,
        Resource::TYPE_BACKUP_POLICY,

        // Integrations
        Resource::TYPE_PLATFORM,
        Resource::TYPE_API_KEY,
        Resource::TYPE_WEBHOOK,
        Resource::TYPE_SMTP,

        // Project
        Resource::TYPE_PROJECT_VARIABLE,
        Resource::TYPE_PROJECT_PROTOCOLS,
        Resource::TYPE_PROJECT_LABELS,
        Resource::TYPE_PROJECT_SERVICES,
        Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
        Resource::TYPE_PROJECT_ENVIRONMENT,

        // Domains
        Resource::TYPE_RULE,

        // legacy
        Resource::TYPE_DOCUMENT,
        Resource::TYPE_ATTRIBUTE,
        Resource::TYPE_COLLECTION,
    ];

    public const ROOT_RESOURCES = [
        Resource::TYPE_BUCKET,
        Resource::TYPE_DATABASE,
        Resource::TYPE_DATABASE_DOCUMENTSDB,
        Resource::TYPE_DATABASE_VECTORSDB,
        Resource::TYPE_FUNCTION,
        Resource::TYPE_SITE,
        Resource::TYPE_USER,
        Resource::TYPE_TEAM,
        Resource::TYPE_PLATFORM,
        Resource::TYPE_API_KEY,
        Resource::TYPE_PROVIDER,
        Resource::TYPE_TOPIC,
        Resource::TYPE_MESSAGE,
    ];

    public const STORAGE_MAX_CHUNK_SIZE = 1024 * 1024 * 5; // 5MB

    protected Source $source;

    protected Destination $destination;

    protected string $currentResource;

    /**
     * A local cache of resources that were transferred.
     */
    protected Cache $cache;

    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * @var array<string, mixed>
     */
    protected array $events = [];

    /**
     * @var array<string, mixed>
     */
    protected array $resources = [];

    private ?ResourceSelector $resourceSelector = null;

    public function __construct(Source $source, Destination $destination)
    {
        $this->source = $source;
        $this->destination = $destination;
        $this->cache = new Cache();

        $this->source->registerCache($this->cache);
        $this->destination->registerCache($this->cache);
        $this->destination->setSource($source);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getStatusCounters(): array
    {
        $status = [];

        foreach ($this->resources as $requestedType) {
            $status[$requestedType] = [
                Resource::STATUS_PENDING => 0,
                Resource::STATUS_SUCCESS => 0,
                Resource::STATUS_ERROR => 0,
                Resource::STATUS_SKIPPED => 0,
                Resource::STATUS_PROCESSING => 0,
                Resource::STATUS_WARNING => 0,
            ];
        }

        if ($this->source->previousReport) {
            foreach ($this->source->previousReport as $reportedType => $reportedTotal) {
                if ($reportedType != 'size' && $reportedType != 'version' && isset($status[$reportedType])) {
                    $status[$reportedType][Resource::STATUS_PENDING] = $reportedTotal;
                }
            }
        }

        foreach ($this->cache->getAll() as $cachedType => $entries) {
            $isRecordType = $cachedType === Resource::TYPE_ROW || $cachedType === Resource::TYPE_DOCUMENT;

            foreach ($entries as $key => $entry) {
                if ($isRecordType && \is_string($entry)) {
                    if (!isset($status[$cachedType])) {
                        continue;
                    }

                    $count = \intval($entry);
                    $status[$cachedType][$key] = $count;

                    if ($status[$cachedType][Resource::STATUS_PENDING] > 0) {
                        $status[$cachedType][Resource::STATUS_PENDING] -= \min($status[$cachedType][Resource::STATUS_PENDING], $count);
                    }

                    continue;
                }

                $resourceType = $entry->getName();

                if (!isset($status[$resourceType])) {
                    continue;
                }

                $status[$resourceType][$entry->getStatus()]++;

                if ($status[$resourceType][Resource::STATUS_PENDING] > 0) {
                    $status[$resourceType][Resource::STATUS_PENDING]--;
                }
            }
        }

        foreach ($this->destination->getErrors() as $error) {
            if (isset($status[$error->getResourceGroup()])) {
                $status[$error->getResourceGroup()][Resource::STATUS_ERROR]++;
            }
        }

        foreach ($this->source->getErrors() as $error) {
            if (isset($status[$error->getResourceGroup()])) {
                $status[$error->getResourceGroup()][Resource::STATUS_ERROR]++;
            }
        }

        foreach ($status as $statusType => $counters) {
            $allEmpty = true;

            foreach ($counters as $counter) {
                if ($counter > 0) {
                    $allEmpty = false;
                }
            }

            if ($allEmpty) {
                unset($status[$statusType]);
            }
        }

        return $status;
    }

    /**
     * Transfer Resources between adapters
     *
     * @param array<string> $resources Resources to transfer
     * @param callable $callback Callback to run after transfer
     * @param string|null $rootResourceId Root resource ID, If enabled you can only transfer a single root resource
     * @throws \Exception
     */
    public function run(
        array $resources,
        callable $callback,
        ?string $rootResourceId = null,
        ?string $rootResourceType = null,
    ): void {
        // Allows you to push entire groups if you want.
        $computedResources = [];
        $rootResourceId = $rootResourceId ?? '';
        $rootResourceType = $rootResourceType ?? '';

        foreach ($resources as $resource) {
            if (is_array($resource)) {
                /** @var array<string> $resource */
                $computedResources = array_merge($computedResources, $resource);
            } else {
                $computedResources[] = $resource;
            }
        }

        /** @var array<string> $computedResources */
        $computedResources = array_map('strtolower', $computedResources);

        if ($rootResourceId !== '') {
            if ($rootResourceType === '') {
                throw new \Exception('Resource type must be set when resource ID is set.');
            }

            if (!in_array($rootResourceType, self::ROOT_RESOURCES)) {
                throw new \Exception('Got '.$rootResourceType.' Resource type must be one of ' . implode(', ', self::ROOT_RESOURCES));
            }

            $rootResources = \array_intersect($computedResources, self::ROOT_RESOURCES);

            if (\count($rootResources) > 1) {
                throw new \Exception('Multiple root resources found. Only one root resource can be transferred at a time if using $rootResourceId.');
            }

            if (\count($rootResources) === 0) {
                throw new \Exception('No root resources found.');
            }
        }

        $this->resources = $computedResources;

        if ($this->resourceSelector !== null) {
            $this->destination->runWithResourceSelector(
                $computedResources,
                $callback,
                $this->resourceSelector->resourceId,
                $this->resourceSelector->resourceInternalId,
                $this->resourceSelector->resourceType,
                $this->resourceSelector->parentResourceId,
                $this->resourceSelector->parentResourceInternalId,
                $this->resourceSelector->parentResourceType,
            );

            return;
        }

        $this->destination->run($computedResources, $callback, $rootResourceId, $rootResourceType);
    }

    /**
     * Transfer resources using Appwrite's canonical resource relation fields.
     *
     * @param array<string|array<string>> $resources Resources to transfer
     * @param callable $callback Callback to run after transfer
     * @throws \Exception
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
        $previousResourceSelector = $this->resourceSelector;
        $this->resourceSelector = new ResourceSelector(
            $resourceId,
            $resourceInternalId,
            $resourceType,
            $parentResourceId,
            $parentResourceInternalId,
            $parentResourceType,
        );

        try {
            $this->run(
                $resources,
                $callback,
                $this->resourceSelector->getScopeId(),
                $this->resourceSelector->getScopeType(),
            );
        } finally {
            $this->resourceSelector = $previousResourceSelector;
        }
    }

    /**
     * Get Cache
     */
    public function getCache(): Cache
    {
        return $this->cache;
    }

    /**
     *  Get Current Resource
     *
     **/
    public function getCurrentResource(): string
    {
        return $this->currentResource;
    }

    /**
     * Get Transfer Report
     *
     * @param  string  $statusLevel If no status level is provided, all status types will be returned.
     * @return array<array<string, mixed>>
     */
    public function getReport(string $statusLevel = ''): array
    {
        $report = [];
        $cache = $this->cache->getAll();

        foreach ($cache as $type => $resources) {
            foreach ($resources as $id => $resource) {
                if (($type === Resource::TYPE_ROW || $type === Resource::TYPE_DOCUMENT) && is_string($resource)) {
                    if ($statusLevel && $resource !== $statusLevel) {
                        continue;
                    }
                    // no message for row is stored
                    $report[] = [
                        'resource' => $type,
                        'id' => $id,
                        'status' => $resource,
                        'message' => '',
                    ];
                    continue;
                }

                if (!is_object($resource)) {
                    continue;
                }

                if ($statusLevel && $resource->getStatus() !== $statusLevel) {
                    continue;
                }

                $report[] = [
                    'resource' => $type,
                    'id' => $resource->getId(),
                    'status' => $resource->getStatus(),
                    'message' => $resource->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * @throws \Exception
     */
    public static function extractServices(array $services): array
    {
        $resources = [];
        foreach ($services as $service) {
            $resources = match ($service) {
                self::GROUP_FUNCTIONS => array_merge($resources, self::GROUP_FUNCTIONS_RESOURCES),
                self::GROUP_SITES => array_merge($resources, self::GROUP_SITES_RESOURCES),
                self::GROUP_STORAGE => array_merge($resources, self::GROUP_STORAGE_RESOURCES),
                self::GROUP_GENERAL => array_merge($resources, []),
                self::GROUP_AUTH => array_merge($resources, self::GROUP_AUTH_RESOURCES),
                self::GROUP_DATABASES => array_merge($resources, self::GROUP_DATABASES_RESOURCES),
                self::GROUP_INTEGRATIONS => array_merge($resources, self::GROUP_INTEGRATIONS_RESOURCES),
                self::GROUP_DATABASES_TABLES_DB => array_merge($resources, self::GROUP_TABLESDB_RESOURCES),
                self::GROUP_DATABASES_DOCUMENTS_DB => array_merge($resources, self::GROUP_DOCUMENTSDB_RESOURCES),
                self::GROUP_DATABASES_VECTOR_DB => array_merge($resources, self::GROUP_VECTORSDB_RESOURCES),
                self::GROUP_MESSAGING => array_merge($resources, self::GROUP_MESSAGING_RESOURCES),
                self::GROUP_BACKUPS => array_merge($resources, self::GROUP_BACKUPS_RESOURCES),
                self::GROUP_PROJECTS => array_merge($resources, self::GROUP_PROJECTS_RESOURCES),
                self::GROUP_DOMAINS => array_merge($resources, self::GROUP_DOMAINS_RESOURCES),
                default => throw new \Exception('No service group found'),
            };
        }

        return $resources;
    }
}
