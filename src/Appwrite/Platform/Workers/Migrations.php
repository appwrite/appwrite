<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Message\Migration;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Template\Template;
use Appwrite\Usage\Context;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationAppwrite;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Destinations\JSON as DestinationJSON;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database as ResourceDatabase;
use Utopia\Migration\Resources\Database\Row as ResourceRow;
use Utopia\Migration\Resources\Database\Table as ResourceTable;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\JSON;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\Hostname;

class Migrations extends Action
{
    protected ?Database $dbForProject;
    protected ?Database $dbForPlatform;
    protected ?Device $deviceForMigrations;
    protected ?Device $deviceForFiles;
    protected ?Document $project;

    protected ?Document $sourceProject = null;

    /**
     * @var callable
     */
    protected mixed $getDatabasesDB;

    /**
     * @var callable(Document $databaseDSN): Database
     */
    protected mixed $getProjectDB;
    protected array $plan = [];

    /**
     * @var array<string, int>
     */
    protected array $sourceReport = [];

    /**
     * @var callable|null
     */
    protected $logError = null;

    public static function getName(): string
    {
        return 'migrations';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Migrations worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('getDatabasesDB')
            ->inject('getProjectDB')
            ->inject('logError')
            ->inject('queueForRealtime')
            ->inject('deviceForMigrations')
            ->inject('deviceForFiles')
            ->inject('publisherForMails')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @throws Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        callable $getDatabasesDB,
        callable $getProjectDB,
        callable $logError,
        Realtime $queueForRealtime,
        Device $deviceForMigrations,
        Device $deviceForFiles,
        MailPublisher $publisherForMails,
        Context $usage,
        UsagePublisher $publisherForUsage,
        array $plan,
        Authorization $authorization,
    ): void {
        $migrationMessage = Migration::fromArray($message->getPayload());
        $this->getDatabasesDB = $getDatabasesDB;
        $this->getProjectDB = $getProjectDB;

        $this->deviceForMigrations = $deviceForMigrations;
        $this->deviceForFiles = $deviceForFiles;
        $this->plan = $plan;

        $migration = $migrationMessage->migration;

        if ($migration->isEmpty()) {
            throw new \Exception('Migration not found');
        }

        if ($project->getId() === 'console') {
            return;
        }

        if ($project->isEmpty()) {
            throw new \Exception('Project not found');
        }

        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;
        $this->project = $project;
        $this->logError = $logError;

        $platform = $migrationMessage->platform ?: Config::getParam('platform', []);

        try {
            $this->processMigration(
                $migration,
                $queueForRealtime,
                $publisherForMails,
                $usage,
                $publisherForUsage,
                $platform,
                $authorization
            );
        } finally {
            $this->dbForProject = null;
            $this->dbForPlatform = null;
            $this->project = null;
            $this->logError = null;
            $this->deviceForMigrations = null;
            $this->deviceForFiles = null;
            $this->plan = [];
            $this->sourceReport = [];

            \gc_collect_cycles();
        }
    }

    /**
     * @throws Exception
     */
    protected function processSource(Document $migration): Source
    {
        $source = $migration->getAttribute('source');
        $destination = $migration->getAttribute('destination');
        [$databaseId, $tableId] = $this->resolveResourceIds($migration);
        $credentials = $migration->getAttribute('credentials');
        $migrationOptions = $migration->getAttribute('options');
        /** @var Database|null $projectDB */
        $projectDB = null;
        $useAppwriteApiSource = false;
        $isAppwriteSource = $source === SourceAppwrite::getName();
        $isAppwriteToAppwrite = $isAppwriteSource
            && $destination === DestinationAppwrite::getName();

        if ($isAppwriteSource && empty($credentials['projectId'])) {
            throw new Exception(Exception::MIGRATION_SOURCE_PROJECT_ID_REQUIRED);
        }

        if ($isAppwriteSource) {
            $this->sourceProject = $this->dbForPlatform->getDocument('projects', $credentials['projectId']);

            // Trust DB fast path only when the source URL targets this cluster's host
            // (env-configured or this project's verified custom API domain).
            $sourceHost = parse_url($credentials['endpoint'] ?? '', PHP_URL_HOST);
            $publicDomain = parse_url('http://' . System::getEnv('_APP_DOMAIN', ''), PHP_URL_HOST) ?: '';
            $internalHost = parse_url('http://' . System::getEnv('_APP_MIGRATION_HOST', ''), PHP_URL_HOST) ?: '';

            $allowedHosts = array_filter([
                $publicDomain,
                $publicDomain !== '' ? '*.' . $publicDomain : null,
                $internalHost,
            ]);

            if (is_string($sourceHost) && !$this->sourceProject->isEmpty()) {
                $rule = $this->dbForPlatform->findOne('rules', [
                    Query::equal('domain', [$sourceHost]),
                    Query::equal('type', ['api']),
                    Query::equal('status', [RULE_STATUS_VERIFIED]),
                    Query::equal('projectInternalId', [$this->sourceProject->getSequence()]),
                ]);
                if (!$rule->isEmpty()) {
                    $allowedHosts[] = $sourceHost;
                }
            }

            $isLocalEndpoint = is_string($sourceHost)
                && !empty($allowedHosts)
                && (new Hostname($allowedHosts))->isValid($sourceHost);

            $sourceRegion = $this->sourceProject->getAttribute('region', 'default');
            $destinationRegion = $this->project->getAttribute('region', 'default');

            $isLocalSource = !$this->sourceProject->isEmpty()
                && $isLocalEndpoint
                && (!$isAppwriteToAppwrite || $sourceRegion === $destinationRegion);

            if ($isLocalSource) {
                $projectDB = call_user_func($this->getProjectDB, $this->sourceProject);
            } elseif ($isAppwriteToAppwrite) {
                $useAppwriteApiSource = true;
            } else {
                throw new Exception(Exception::MIGRATION_SOURCE_PROJECT_NOT_FOUND);
            }
        }
        $getDatabasesDB = fn (Document $database): Database =>
                $this->getDatabasesDBForProject($database);
        $queries = [];
        if ($source === SourceAppwrite::getName() && in_array($destination, [DestinationCSV::getName(), DestinationJSON::getName()])) {
            $queries = Query::parseQueries($migrationOptions['queries'] ?? []);
        }

        $migrationSource = match ($source) {
            Firebase::getName() => new Firebase(
                json_decode($credentials['serviceAccount'], true),
            ),
            Supabase::getName() => new Supabase(
                $credentials['endpoint'],
                $credentials['apiKey'],
                $credentials['databaseHost'],
                'postgres',
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            NHost::getName() => new NHost(
                $credentials['subdomain'],
                $credentials['region'],
                $credentials['adminSecret'],
                $credentials['database'],
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            SourceAppwrite::getName() => new SourceAppwrite(
                $credentials['projectId'],
                $credentials['endpoint'],
                $credentials['apiKey'],
                $getDatabasesDB,
                $useAppwriteApiSource ? SourceAppwrite::SOURCE_API : SourceAppwrite::SOURCE_DATABASE,
                $projectDB,
                $queries
            ),
            CSV::getName() => CSV::fromResourceIds(
                databaseId: $databaseId,
                tableId: $tableId,
                filePath: $migrationOptions['path'],
                device: $this->deviceForMigrations,
                dbForProject: $this->dbForProject,
                getDatabasesDB: $getDatabasesDB,
            ),
            JSON::getName() => JSON::fromResourceIds(
                databaseId: $databaseId,
                tableId: $tableId,
                filePath: $migrationOptions['path'],
                device: $this->deviceForMigrations,
                dbForProject: $this->dbForProject,
            ),
            default => throw new Exception(Exception::MIGRATION_SOURCE_TYPE_INVALID),
        };

        $resources = $migration->getAttribute('resources', []);
        $this->sourceReport = $migrationSource->report($resources);

        return $migrationSource;
    }

    /**
     * @throws Exception
     */
    protected function processDestination(Document $migration): Destination
    {
        $destination = $migration->getAttribute('destination');
        $options = $migration->getAttribute('options', []);
        $credentials = $migration->getAttribute('credentials');
        [$databaseId, $tableId] = $this->resolveResourceIds($migration);

        return match ($destination) {
            DestinationAppwrite::getName() => new DestinationAppwrite(
                $this->project->getId(),
                $credentials['destinationEndpoint'],
                $credentials['destinationApiKey'],
                $this->dbForProject,
                $this->getDatabasesDB,
                Config::getParam('collections', [])['databases']['collections'],
                $this->dbForPlatform,
                $this->project->getSequence(),
                OnDuplicate::tryFrom($options['onDuplicate'] ?? '') ?? OnDuplicate::Fail,
                $this->resolveDestinationDatabaseDsn(...),
            ),
            DestinationCSV::getName() => DestinationCSV::fromResourceIds(
                deviceForFiles: $this->deviceForFiles,
                databaseId: $databaseId,
                tableId: $tableId,
                directory: $options['bucketId'],
                filename: $migration->getId(),
                allowedColumns: $options['columns'],
                delimiter: $options['delimiter'],
                enclosure: $options['enclosure'],
                escape: $options['escape'],
                includeHeaders: $options['header'],
            ),
            DestinationJSON::getName() => DestinationJSON::fromResourceIds(
                deviceForFiles: $this->deviceForFiles,
                databaseId: $databaseId,
                tableId: $tableId,
                directory: $options['bucketId'] ?? 'default',
                filename: $migration->getId(),
                allowedColumns: $options['columns'] ?? [],
            ),
            default => throw new Exception(Exception::MIGRATION_DESTINATION_TYPE_INVALID),
        };
    }

    /**
     * Legacy / tablesdb databases route to the destination project's DSN (same as a fresh
     * Databases create), while documentsdb / vectorsdb keep the source DSN — the dedicated-DB
     * backfill that would re-point them is not run during migrations.
     */
    private function resolveDestinationDatabaseDsn(ResourceDatabase $resource): string
    {
        return match ($resource->getType()) {
            DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB => (string) $resource->getDatabase(),
            default => (string) $this->project->getAttribute('database', ''),
        };
    }

    /**
     * @throws AuthorizationException
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
    {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('migrations.[migrationId].update')
            ->setParam('migrationId', $migration->getId())
            ->setPayload($migration->getArrayCopy(), sensitive: ['credentials'])
            ->trigger();

        return $this->dbForProject->updateDocument(
            'migrations',
            $migration->getId(),
            $migration
        );
    }

    /**
     * @return array<string>
     */
    protected function getAPIKeyScopes(): array
    {
        return [
            'users.read',
            'users.write',
            'teams.read',
            'teams.write',
            'buckets.read',
            'buckets.write',
            'files.read',
            'files.write',
            'functions.read',
            'functions.write',
            'sites.read',
            'sites.write',
            'tokens.read',
            'tokens.write',
            'providers.read',
            'providers.write',
            'topics.read',
            'topics.write',
            'subscribers.read',
            'subscribers.write',
            'messages.read',
            'messages.write',
            'targets.read',
            'targets.write',
            'webhooks.read',
            'webhooks.write',
            'rules.read',
            'rules.write',
            'project.read',
            'project.write',
            'keys.read',
            'keys.write',
            'platforms.read',
            'platforms.write',
            'mocks.read',
            'mocks.write',
            'project.policies.read',
            'project.policies.write',
            'project.oauth2.read',
            'project.oauth2.write',
            'templates.read',
            'templates.write',
        ];
    }

    /**
     * @throws Exception
     */
    protected function generateAPIKey(Document $project): string
    {
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 86400, 0);

        $apiKey = $jwt->encode([
            'projectId' => $project->getId(),
            'disabledMetrics' => [
                METRIC_DATABASES_OPERATIONS_READS,
                METRIC_DATABASES_OPERATIONS_WRITES,
                METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB,
                METRIC_DATABASES_OPERATIONS_WRITES_DOCUMENTSDB,
                METRIC_DATABASES_OPERATIONS_READS_VECTORSDB,
                METRIC_DATABASES_OPERATIONS_WRITES_VECTORSDB,
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
            ],
            'scopes' => $this->getAPIKeyScopes(),
        ]);

        return API_KEY_EPHEMERAL . '_' . $apiKey;
    }

    /**
     * @throws AuthorizationException
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function processMigration(
        Document $migration,
        Realtime $queueForRealtime,
        MailPublisher $publisherForMails,
        Context $usage,
        UsagePublisher $publisherForUsage,
        array $platform,
        Authorization $authorization,
    ): void {
        $project = $this->project;

        $tempAPIKey = $this->generateAPIKey($project);

        $transfer = $source = $destination = null;
        $aggregatedResources = [];
        $caughtError = null;

        $host = System::getEnv('_APP_MIGRATION_HOST');
        if (empty($host)) {
            throw new \Exception('_APP_MIGRATION_HOST is not set');
        }

        $endpoint = 'http://' . $host . '/v1';

        try {
            $credentials = $migration->getAttribute('credentials', []);

            if ($migration->getAttribute('source') === SourceAppwrite::getName()) {
                $credentials['projectId'] = $credentials['projectId'] ?? $project->getId();
                $credentials['apiKey'] = $credentials['apiKey'] ?? $tempAPIKey;
                $credentials['endpoint'] = $credentials['endpoint'] ?? $endpoint;
            }

            if ($migration->getAttribute('destination') === DestinationAppwrite::getName()) {
                $credentials['destinationApiKey'] = $tempAPIKey;
                $credentials['destinationEndpoint'] = $endpoint;
            }

            $migration->setAttribute('credentials', $credentials);

            $migration->setAttribute('stage', 'processing');
            $migration->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migration, $project, $queueForRealtime);

            $source = $this->processSource($migration);
            $destination = $this->processDestination($migration);

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            if (empty($source->getErrors())) {
                $migration->setAttribute('stage', 'migrating');
                $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                $context = $this->resolveResourceContext($migration);
                $transfer->runWithResourceSelector(
                    $migration->getAttribute('resources'),
                    function ($resources) use ($migration, $transfer, $project, $queueForRealtime, &$aggregatedResources) {
                        $migration->setAttribute('resourceData', json_encode($transfer->getCache()));
                        $migration->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));

                        if (!empty($resources)) {
                            /**
                             * @var Resource $resource
                            */
                            $resource = $resources[0];
                            $count = count($resources);
                            $databaseId = null;
                            $tableId = null;
                            switch ($resource->getName()) {
                                case ResourceTable::getName():
                                    /** @var ResourceTable $resource */
                                    $databaseId = $resource->getDatabase()->getSequence();
                                    break;
                                case ResourceRow::getName():
                                    /** @var ResourceRow $resource */
                                    $table = $resource->getTable();
                                    $databaseId = $table->getDatabase()->getSequence();
                                    $tableId = $table->getSequence();
                                    break;
                                default:
                                    break;
                            }
                            $aggregatedResources[] = [
                                'name' => $resource->getName(),
                                'count' => $count,
                                'databaseId' => $databaseId,
                                'tableId' => $tableId
                            ];

                        }
                        $this->updateMigrationDocument($migration, $project, $queueForRealtime);
                    },
                    resourceId: $context['resourceId'],
                    resourceInternalId: $context['resourceInternalId'],
                    resourceType: $context['resourceType'],
                    parentResourceId: $context['parentResourceId'],
                    parentResourceInternalId: $context['parentResourceInternalId'],
                    parentResourceType: $context['parentResourceType'],
                );

                $destination->shutdown();
                $source->shutdown();
            }

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();

            if (!empty($sourceErrors) || ! empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');
                return;
            }

            $destination->success();
            $source->success();

            $destinationType = $migration->getAttribute('destination');
            if ($destinationType === DestinationCSV::getName() || $destinationType === DestinationJSON::getName()) {
                $this->handleDataExportComplete($project, $migration, $publisherForMails, $queueForRealtime, $platform, $authorization);
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error('Message: ' . $th->getMessage());
            Console::error('File: ' . $th->getFile());
            Console::error('Line: ' . $th->getLine());
            Console::error($th->getTraceAsString());

            $migration->setAttribute('status', 'failed');
            $migration->setAttribute('stage', 'finished');

            $caughtError = $th;

            // Mirror general.php's HTTP-error pattern: typed AppwriteException uses its
            // registry-driven isPublishable() flag; library-thrown Migration\Exception is
            // always user-facing; anything else is unknown and surfaced to Sentry.
            if ($th instanceof Exception) {
                $publish = $th->isPublishable();
            } elseif ($th instanceof MigrationException) {
                $publish = false;
            } else {
                $publish = true;
            }

            if ($publish) {
                $extras = [
                    'migrationId' => $migration->getId(),
                    'source' => $migration->getAttribute('source') ?? '',
                    'destination' => $migration->getAttribute('destination') ?? '',
                ];

                // Include source identifiers for Appwrite sources to make Sentry events
                // self-debuggable. Never include the apiKey or any other secret.
                if ($migration->getAttribute('source') === SourceAppwrite::getName()) {
                    $credentials = $migration->getAttribute('credentials', []) ?? [];
                    $extras['sourceProjectId'] = $credentials['projectId'] ?? '';
                    $extras['sourceEndpoint'] = $credentials['endpoint'] ?? '';
                }

                $this->reportError($th, $migration, $extras);
            }
        } finally {
            try {
                $sourceErrors = $source?->getErrors() ?? [];
                $destinationErrors = $destination?->getErrors() ?? [];

                if ($caughtError !== null) {
                    if ($caughtError instanceof MigrationException) {
                        // library-thrown, message constructed by us
                        $bubbled = $caughtError;
                    } elseif ($caughtError instanceof Exception) {
                        // typed AppwriteException — message comes from the curated registry
                        $bubbled = new MigrationException(
                            resourceName: '',
                            resourceGroup: '',
                            message: $caughtError->getMessage(),
                            code: $caughtError->getCode(),
                            previous: $caughtError,
                        );
                    } else {
                        // unknown throwable — raw message may embed internal hostnames,
                        // DSNs, tokens, etc. Replace with a generic user-facing string;
                        // the original is preserved on `previous:` for Sentry.
                        $bubbled = new MigrationException(
                            resourceName: '',
                            resourceGroup: '',
                            message: 'Migration failed due to an unexpected error.',
                            code: $caughtError->getCode() ?: 500,
                            previous: $caughtError,
                        );
                    }
                    $destinationErrors[] = $bubbled;
                }

                $migration->setAttribute('errors', $this->sanitizeErrors(
                    $sourceErrors,
                    $destinationErrors,
                ));

                $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                if ($migration->getAttribute('status', '') === 'failed') {
                    Console::error('Migration(' . $migration->getSequence() . ':' . $migration->getId() . ') failed, Project(' . $this->project->getSequence() . ':' . $this->project->getId() . ')');

                    $source?->error();
                    $destination?->error();
                }

                if ($migration->getAttribute('status', '') === 'completed') {
                    foreach ($aggregatedResources as $resource) {
                        $this->processMigrationResourceStats(
                            $resource,
                            $usage,
                            $project,
                            $publisherForUsage,
                            $migration->getAttribute('source'),
                            $authorization,
                            ...$this->resolveResourceIds($migration),
                        );
                    }
                }
            } finally {
                $source?->cleanup();
                $destination?->cleanup();

                $transfer = null;
                $source = null;
                $destination = null;
            }
        }
    }

    protected function getDatabasesDBForProject(Document $database)
    {
        if (isset($this->sourceProject) && ! $this->sourceProject->isEmpty()) {
            return ($this->getDatabasesDB)($database, $this->sourceProject);
        }

        return ($this->getDatabasesDB)($database);
    }

    /** @return array{0: string, 1: string} */
    protected function resolveResourceIds(Document $migration): array
    {
        $context = $this->resolveResourceContext($migration);

        if ($context['parentResourceId'] !== '') {
            return [$context['parentResourceId'], $context['resourceId']];
        }

        return [$context['resourceId'], ''];
    }

    /**
     * @return array{resourceId: string, resourceInternalId: string, resourceType: string, parentResourceId: string, parentResourceInternalId: string, parentResourceType: string}
     */
    protected function resolveResourceContext(Document $migration): array
    {
        $context = [
            'resourceId' => (string) $migration->getAttribute('resourceId', ''),
            'resourceInternalId' => (string) $migration->getAttribute('resourceInternalId', ''),
            'resourceType' => (string) $migration->getAttribute('resourceType', ''),
            'parentResourceId' => (string) $migration->getAttribute('parentResourceId', ''),
            'parentResourceInternalId' => (string) $migration->getAttribute('parentResourceInternalId', ''),
            'parentResourceType' => (string) $migration->getAttribute('parentResourceType', ''),
        ];

        if (
            $context['parentResourceId'] === ''
            && \array_key_exists($context['resourceType'], Resource::DATABASE_TYPE_RESOURCE_MAP)
            && \str_contains($context['resourceId'], ':')
        ) {
            [$context['parentResourceId'], $context['resourceId']] = \explode(':', $context['resourceId'], 2);
            $context['parentResourceType'] = $context['resourceType'];
            $context['resourceType'] = Resource::TYPE_COLLECTION;
        }

        return $context;
    }

    /**
     * Handle actions to be performed when a CSV export migration is successfully completed
     *
     * @param Document $project
     * @param Document $migration
     * @param MailPublisher $publisherForMails
     * @param Realtime $queueForRealtime
     * @param array $platform
     * @param Authorization $authorization
     * @return void
     */
    protected function handleDataExportComplete(
        Document $project,
        Document $migration,
        MailPublisher $publisherForMails,
        Realtime $queueForRealtime,
        array $platform,
        Authorization $authorization,
    ): void {
        $options = $migration->getAttribute('options', []);
        $bucketId = 'default'; // Always use platform default bucket
        $filename = $options['filename'] ?? 'export_' . \time();
        $user = $this->resolveExportUser($migration);

        $bucket = $this->dbForPlatform->getDocument('buckets', $bucketId);
        if ($bucket->isEmpty()) {
            throw new \Exception('Bucket not found');
        }

        $extension = $migration->getAttribute('destination') === DestinationJSON::getName() ? '.json' : '.csv';
        $path = $this->deviceForFiles->getPath($bucketId . '/' . $migration->getId() . $extension);
        $size = $this->deviceForFiles->getFileSize($path);
        $mime = $this->deviceForFiles->getFileMimeType($path);
        $hash = $this->deviceForFiles->getFileHash($path);
        $algorithm = Compression::NONE;
        $fileId = ID::unique();

        $sizeMB = \round($size / (1000 * 1000), 2);

        $planFileSize = empty($this->plan['fileSize'])
            ? PHP_INT_MAX
            : $this->plan['fileSize'];

        if ($sizeMB > $planFileSize) {
            try {
                $this->deviceForFiles->delete($path);
            } finally {
                $message = "Export file size {$sizeMB}MB exceeds your plan limit.";

                $errors = $migration->getAttribute('errors', []);
                $errors[] = json_encode(['code' => 0, 'message' => $message]);
                $migration->setAttribute('errors', $errors);
                $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                $this->notifyExport(
                    migration: $migration,
                    success: false,
                    project: $project,
                    user: $user,
                    options: $options,
                    publisherForMails: $publisherForMails,
                    platform: $platform,
                    exportType: $migration->getAttribute('destination') === DestinationJSON::getName() ? 'JSON' : 'CSV',
                    sizeMB: $sizeMB
                );

                throw new \Exception($message);
            }
        }

        $permissions = [];
        if (!$user->isEmpty()) {
            $permissions[] = Permission::read(Role::user($user->getId()));
        }

        $this->dbForPlatform->createDocument('bucket_' . $bucket->getSequence(), new Document([
            '$id' => $fileId,
            '$permissions' => $permissions,
            'bucketId' => $bucket->getId(),
            'bucketInternalId' => $bucket->getSequence(),
            'name' => $filename,
            'path' => $path,
            'signature' => $hash,
            'mimeType' => $mime,
            'sizeOriginal' => $size,
            'sizeActual' => $size,
            'algorithm' => $algorithm,
            'comment' => '',
            'chunksTotal' => 1,
            'chunksUploaded' => 1,
            'openSSLVersion' => null,
            'openSSLCipher' => null,
            'openSSLTag' => null,
            'openSSLIV' => null,
            'search' => \implode(' ', [$fileId, $filename]),
            'metadata' => ['content_type' => $mime]
        ]));

        Console::info("Created file document in bucket: $fileId");

        // Generate JWT valid for 1 hour
        $maxAge = 60 * 60;
        $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $maxAge, 0);
        $jwt = $encoder->encode([
            'bucketId' => $bucketId,
            'fileId' => $fileId,
            'projectId' => $project->getId(),
            'internal' => true,
            'disposition' => 'attachment',
        ]);

        // Generate download URL with JWT
        $endpoint = System::getEnv('_APP_DOMAIN', '');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled' ? 'https' : 'http';

        $downloadUrl = "{$protocol}://{$endpoint}/v1/storage/buckets/{$bucketId}/files/{$fileId}/push?project={$project->getId()}&jwt={$jwt}";

        $options['downloadUrl'] = $downloadUrl;
        $migration->setAttribute('options', $options);
        $this->updateMigrationDocument($migration, $project, $queueForRealtime);

        $this->notifyExport(
            migration: $migration,
            success: true,
            project: $project,
            user: $user,
            options: $options,
            publisherForMails: $publisherForMails,
            platform: $platform,
            exportType: $migration->getAttribute('destination') === DestinationJSON::getName() ? 'JSON' : 'CSV',
            downloadUrl: $downloadUrl
        );
    }

    protected function resolveExportUser(Document $migration): Document
    {
        $userInternalId = $migration->getAttribute('options', [])['userInternalId'] ?? null;
        if (\is_string($userInternalId) && \ctype_digit($userInternalId)) {
            $userInternalId = (int) $userInternalId;
        }

        if ($userInternalId === null || $userInternalId === '') {
            Console::warning('Finalizing export without a user permission for migration ' . $migration->getId() . ': no initiating user.');
            return new Document([]);
        }

        $valid = \is_string($userInternalId) || (\is_int($userInternalId) && $userInternalId > 0);
        if (!$valid) {
            $error = new \UnexpectedValueException('Invalid initiating user sequence for export migration.');
            Console::error($error->getMessage() . ' Migration: ' . $migration->getId());
            $this->reportError($error, $migration);
            return new Document([]);
        }

        $user = $this->dbForPlatform->findOne('users', [
            Query::equal('$sequence', [$userInternalId])
        ]);

        if ($user->isEmpty()) {
            $error = new \RuntimeException('Initiating user not found for export migration.');
            Console::error($error->getMessage() . ' Migration: ' . $migration->getId());
            $this->reportError($error, $migration);
        }

        return $user;
    }

    protected function notifyExport(
        Document $migration,
        bool $success,
        Document $project,
        Document $user,
        array $options,
        MailPublisher $publisherForMails,
        array $platform,
        string $exportType = 'CSV',
        string $downloadUrl = '',
        float $sizeMB = 0.0,
    ): void {
        try {
            $this->sendExportEmail(
                success: $success,
                project: $project,
                user: $user,
                options: $options,
                publisherForMails: $publisherForMails,
                platform: $platform,
                exportType: $exportType,
                downloadUrl: $downloadUrl,
                sizeMB: $sizeMB,
            );
        } catch (\Throwable $error) {
            Console::error('Failed to send the export notification for migration ' . $migration->getId() . ': ' . $error->getMessage());
            $this->reportError($error, $migration);
        }
    }

    /**
     * @param array<string, mixed> $extras
     */
    protected function reportError(\Throwable $error, Document $migration, array $extras = []): void
    {
        if (!\is_callable($this->logError)) {
            return;
        }

        try {
            ($this->logError)(
                $error,
                'appwrite-worker',
                'appwrite-queue-' . self::getName(),
                [
                    'migrationId' => $migration->getId(),
                    'source' => $migration->getAttribute('source', ''),
                    'destination' => $migration->getAttribute('destination', ''),
                    ...$extras,
                ]
            );
        } catch (\Throwable $loggingError) {
            Console::error('Failed to report the migration error: ' . $loggingError->getMessage());
        }
    }

    /**
     * Send CSV export notification email
     *
     * @param bool $success Whether the export was successful
     * @param Document $project
     * @param Document $user The user who triggered the operation
     * @param array $options Migration options
     * @param MailPublisher $publisherForMails
     * @param array $platform
     * @param string $downloadUrl Download URL for successful exports
     * @param float $sizeMB File size in MB for failed exports
     * @return void
     * @throws \Exception
     */
    protected function sendExportEmail(
        bool $success,
        Document $project,
        Document $user,
        array $options,
        MailPublisher $publisherForMails,
        array $platform,
        string $exportType = 'CSV',
        string $downloadUrl = '',
        float $sizeMB = 0.0,
    ): void {
        if (!($options['notify'] ?? false)) {
            return;
        }

        if ($user->isEmpty()) {
            Console::warning("User not found for CSV export notification: {$user->getSequence()}");
            return;
        }

        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        $emailType = $success
            ? 'success'
            : 'failure';

        // Get localized email content — replace {{type}} with export format (CSV/JSON)
        $subject = \str_replace('{{type}}', $exportType, $locale->getText("emails.dataExport.{$emailType}.subject"));
        $preview = \str_replace('{{type}}', $exportType, $locale->getText("emails.dataExport.{$emailType}.preview"));
        $hello = $locale->getText("emails.dataExport.{$emailType}.hello");
        $body = $locale->getText("emails.dataExport.{$emailType}.body");
        $footer = $locale->getText("emails.dataExport.{$emailType}.footer");
        $thanks = $locale->getText("emails.dataExport.{$emailType}.thanks");
        $signature = $locale->getText("emails.dataExport.{$emailType}.signature");
        $buttonText = $success ? $locale->getText("emails.dataExport.{$emailType}.buttonText") : '';

        // Build email body using appropriate template
        $templatePath = $success
            ? __DIR__ . '/../../../../app/config/locale/templates/email-inner-base.tpl'
            : __DIR__ . '/../../../../app/config/locale/templates/email-export-failed.tpl';

        $message = Template::fromFile($templatePath)
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $hello)
            ->setParam('{{footer}}', $footer)
            ->setParam('{{thanks}}', $thanks)
            ->setParam('{{signature}}', $signature)
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name'))
            ->setParam('{{user}}', $user->getAttribute('name', $user->getAttribute('email')))
            ->setParam('{{type}}', $exportType)
            ->setParam('{{size}}', $success ? '' : (string)$sizeMB);

        if ($success) {
            $message
                ->setParam('{{buttonText}}', $buttonText)
                ->setParam('{{redirect}}', $downloadUrl);
        }

        $emailBody = $message->render();

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'logoUrl' => $platform['logoUrl'],
            'accentColor' => $platform['accentColor'],
            'twitter' => $platform['twitterUrl'],
            'discord' => $platform['discordUrl'],
            'github' => $platform['githubUrl'],
            'terms' => $platform['termsUrl'],
            'privacy' => $platform['privacyUrl'],
            'platform' => $platform['platformName'],
            'type' => $exportType,
        ];

        $publisherForMails->enqueue(new MailMessage(
            project: $project,
            recipient: $user->getAttribute('email'),
            name: $user->getAttribute('name', $user->getAttribute('email')),
            subject: $subject,
            template: MAIL_TEMPLATE_DATA_EXPORT,
            bodyTemplate: __DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl',
            body: $emailBody,
            preview: $preview,
            variables: $emailVariables,
            customMailOptions: ['senderName' => $platform['emailSenderName']],
            platform: $platform,
        ));

        Console::info("CSV export {$emailType} notification email sent to " . $user->getAttribute('email'));
    }

    /**
     * Sanitize migration errors, removing sensitive information like stack traces
     *
     * @param array $sourceErrors
     * @param array $destinationErrors
     * @return array
     */
    protected function sanitizeErrors(
        array $sourceErrors,
        array $destinationErrors,
    ): array {
        $errors = [];
        foreach ([...$sourceErrors, ...$destinationErrors] as $error) {
            $encoded = \json_decode(\json_encode($error), true);
            if (\is_array($encoded)) {
                if (isset($encoded['trace'])) {
                    unset($encoded['trace']);
                }
                $errors[] = \json_encode($encoded);
            } else {
                $errors[] = \json_encode($error);
            }
        }

        return $errors;
    }

    private function processMigrationResourceStats(array $resources, Context $usage, Document $projectDocument, UsagePublisher $publisherForUsage, string $source, Authorization $authorization, ?string $parentResourceId, ?string $resourceId)
    {
        $resourceName = $resources['name'];
        $count = $resources['count'];
        $databaseInternalId = $resources['databaseId'];
        $tableInternalId = $resources['tableId'];

        if ($source === CSV::getName()) {
            if (empty($parentResourceId) || empty($resourceId)) {
                Console::warning("Skipping CSV migration usage stats: missing parent/leaf resource ID (parent: '{$parentResourceId}', leaf: '{$resourceId}')");
                return;
            }
            $database = $authorization->skip(fn () => $this->dbForProject->getDocument('databases', $parentResourceId));
            if ($database->isEmpty()) {
                Console::warning("Skipping CSV migration usage stats: database '{$parentResourceId}' not found");
                return;
            }
            $table = $authorization->skip(fn () => $this->dbForProject->getDocument('database_' . $database->getSequence(), $resourceId));
            if ($table->isEmpty()) {
                Console::warning("Skipping CSV migration usage stats: collection '{$resourceId}' not found in database '{$parentResourceId}'");
                return;
            }
            $databaseInternalId = (int) $database->getSequence();
            $tableInternalId = (int) $table->getSequence();
        }

        switch ($resourceName) {
            case ResourceDatabase::getName():
                $usage->addMetric(METRIC_DATABASES, $count);
                break;

            case ResourceTable::getName():
                $usage
                    ->addMetric(METRIC_COLLECTIONS, $count)
                    ->addMetric(
                        str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_COLLECTIONS),
                        $count
                    );
                break;

            case ResourceRow::getName():
                $usage
                    ->addMetric(
                        str_replace(
                            ['{databaseInternalId}','{collectionInternalId}'],
                            [$databaseInternalId, $tableInternalId],
                            METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS
                        ),
                        $count
                    )
                    ->addMetric(
                        str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_DOCUMENTS),
                        $count
                    )
                    ->addMetric(METRIC_DOCUMENTS, $count);
                break;

            default:
                break;
        }

        $message = new UsageMessage(
            project: $projectDocument,
            metrics: $usage->getMetrics(),
            reduce: $usage->getReduce()
        );
        $publisherForUsage->enqueue($message);
        $usage->reset();
    }
}
