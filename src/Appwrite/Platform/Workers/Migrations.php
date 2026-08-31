<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Message\Migration;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Modules\Migrations\Superseded;
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
use Utopia\Migration\Destinations\Appwrite\ProvisioningOwner;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Destinations\JSON as DestinationJSON;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database as ResourceDatabase;
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
    protected ?Claim $claims = null;
    protected ?Document $terminal = null;

    protected ?Document $sourceProject = null;

    /** @var (\Closure(Document, ?Document=): Database)|null */
    protected ?\Closure $getDatabasesDB = null;

    /** @var (\Closure(Document): Database)|null */
    protected ?\Closure $getProjectDB = null;
    protected array $plan = [];

    /**
     * @var array<string, int>
     */
    protected array $sourceReport = [];

    protected ?\Closure $logError = null;

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
            ->inject('locks')
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
        callable $locks,
    ): void {
        $this->reset();

        $migrationMessage = Migration::fromArray($message->getPayload());
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

        $claims = new Claim($dbForProject, $locks);
        $delivery = $claims->consume($project->getId(), $migrationMessage);
        if ($delivery === null) {
            return;
        }

        $migration = $delivery->migration;
        $this->claims = $claims;
        $this->terminal = $delivery->terminal;
        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;
        $this->project = $project;
        $this->getDatabasesDB = \Closure::fromCallable($getDatabasesDB);
        $this->getProjectDB = \Closure::fromCallable($getProjectDB);
        $this->logError = \Closure::fromCallable($logError);
        $this->deviceForMigrations = $deviceForMigrations;
        $this->deviceForFiles = $deviceForFiles;
        $this->plan = $plan;

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
            $this->reset();

            \gc_collect_cycles();
        }
    }

    private function reset(): void
    {
        $this->dbForProject = null;
        $this->dbForPlatform = null;
        $this->deviceForMigrations = null;
        $this->deviceForFiles = null;
        $this->project = null;
        $this->claims = null;
        $this->terminal = null;
        $this->sourceProject = null;
        $this->getDatabasesDB = null;
        $this->getProjectDB = null;
        $this->plan = [];
        $this->sourceReport = [];
        $this->logError = null;
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
                $getProjectDB = $this->getProjectDB ?? throw new \LogicException('Project database resolver is missing');
                $projectDB = $getProjectDB($this->sourceProject);
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
                project: $this->project->getId(),
                endpoint: $credentials['destinationEndpoint'],
                key: $credentials['destinationApiKey'],
                dbForProject: $this->dbForProject,
                getDatabasesDB: $this->getDatabasesDB ?? throw new \LogicException('Database resolver is missing'),
                collectionStructure: Config::getParam('collections', [])['databases']['collections'],
                dbForPlatform: $this->dbForPlatform,
                projectInternalId: $this->project->getSequence(),
                owner: $this->provisioningOwner($migration),
                getRecoverableOwner: fn (Document $database): ?ProvisioningOwner => $this->claims?->recoverable($database, $this->terminal),
                onDuplicate: OnDuplicate::tryFrom($options['onDuplicate'] ?? '') ?? OnDuplicate::Fail,
                getDatabaseDSN: $this->resolveDestinationDatabaseDsn(...),
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

    private function provisioningOwner(Document $migration): ProvisioningOwner
    {
        $attemptId = $migration->getAttribute('attemptId');
        if (!\is_string($attemptId) || $attemptId === '') {
            throw new \LogicException('Migration attempt identifier is missing');
        }

        return new ProvisioningOwner($migration->getId(), $attemptId);
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
     * @throws Superseded
     */
    protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
    {
        $claims = $this->claims ?? throw new \LogicException('Migration claim is missing');
        $stored = $claims->persist($migration);
        if ($stored === null) {
            throw new Superseded('Migration attempt was superseded');
        }

        try {
            $queueForRealtime
                ->setProject($project)
                ->setSubscribers(['console', $project->getId()])
                ->setEvent('migrations.[migrationId].update')
                ->setParam('migrationId', $stored->getId())
                ->setPayload($stored->getArrayCopy(), sensitive: ['credentials'])
                ->trigger();
        } catch (\Throwable $error) {
            Console::warning('Failed to publish migration update: ' . $error->getMessage());
        }

        return $stored;
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
        $transfer = $source = $destination = null;
        $caughtError = null;
        $superseded = false;

        try {
            $tempAPIKey = $this->generateAPIKey($project);

            $host = System::getEnv('_APP_MIGRATION_HOST');
            if (empty($host)) {
                throw new \Exception('_APP_MIGRATION_HOST is not set');
            }

            $endpoint = 'http://' . $host . '/v1';

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

            if (
                $migration->getAttribute('stage') !== 'processing'
                || $migration->getAttribute('status') !== 'processing'
            ) {
                $migration->setAttribute('stage', 'processing');
                $migration->setAttribute('status', 'processing');
                $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);
            }

            $source = $this->processSource($migration);
            $destination = $this->processDestination($migration);

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            if (empty($source->getErrors())) {
                $migration->setAttribute('stage', 'migrating');
                $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                $context = $this->resolveResourceContext($migration);
                $transfer->runWithResourceSelector(
                    $migration->getAttribute('resources'),
                    function ($resources) use (&$migration, $transfer, $project, $queueForRealtime) {
                        $migration->setAttribute('resourceData', json_encode($transfer->getReport()));
                        $migration->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));
                        $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);
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

            // Persist a non-expirable terminal-side-effect claim before success hooks.
            // A superseded worker stops here and cannot mark databases ready or clean orphans.
            $migration->setAttribute('status', 'processing');
            $migration->setAttribute('stage', 'finalizing');
            $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);

            $destination->success();
            $source->success();

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();
            if (!empty($sourceErrors) || ! empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');
                return;
            }

            $destinationType = $migration->getAttribute('destination');
            if ($destinationType === DestinationCSV::getName() || $destinationType === DestinationJSON::getName()) {
                $migration = $this->handleDataExportComplete($project, $migration, $publisherForMails, $queueForRealtime, $platform, $authorization);
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (Superseded $th) {
            $superseded = true;
            Console::warning($th->getMessage());
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
                if ($superseded) {
                    return;
                }

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

                try {
                    $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);
                } catch (Superseded $error) {
                    Console::warning($error->getMessage());
                    return;
                }

                if ($migration->getAttribute('status', '') === 'failed') {
                    Console::error('Migration(' . $migration->getSequence() . ':' . $migration->getId() . ') failed, Project(' . $this->project->getSequence() . ':' . $this->project->getId() . ')');

                    $source?->error();
                    $destination?->error();
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

    protected function getDatabasesDBForProject(Document $database): Database
    {
        $getDatabasesDB = $this->getDatabasesDB ?? throw new \LogicException('Database resolver is missing');

        if (isset($this->sourceProject) && ! $this->sourceProject->isEmpty()) {
            return $getDatabasesDB($database, $this->sourceProject);
        }

        return $getDatabasesDB($database);
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
     * @return Document
     */
    protected function handleDataExportComplete(
        Document $project,
        Document $migration,
        MailPublisher $publisherForMails,
        Realtime $queueForRealtime,
        array $platform,
        Authorization $authorization,
    ): Document {
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
        $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);

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

        return $migration;
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
        if ($this->logError === null) {
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
        $locale->setFallback('en');

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
}
