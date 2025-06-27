<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Realtime;
use Exception;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationAppwrite;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

class Migrations extends Action
{
    protected Database $dbForProject;

    protected Database $dbForPlatform;

    protected Device $deviceForImports;

    protected Document $project;

    /**
     * Cached for performance.
     *
     * @var array<string, int>
     */
    protected array $sourceReport = [];

    /**
     * @var callable
     */
    protected $logError;

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
            ->inject('logError')
            ->inject('queueForRealtime')
            ->inject('deviceForImports')
            ->callback([$this, 'action']);
    }

    /**
     * @throws Exception
     */
    public function action(Message $message, Document $project, Database $dbForProject, Database $dbForPlatform, callable $logError, Realtime $queueForRealtime, Device $deviceForImports): void
    {
        $payload = $message->getPayload() ?? [];
        $this->deviceForImports = $deviceForImports;

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events    = $payload['events'] ?? [];
        $migration = new Document($payload['migration'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;
        $this->project = $project;
        $this->logError = $logError;

        /**
         * Handle Event execution.
         */
        if (! empty($events)) {
            return;
        }

        $this->processMigration($migration, $queueForRealtime);
    }

    /**
     * @throws Exception
     */
    protected function processSource(Document $migration): Source
    {
        $source = $migration->getAttribute('source');
        $resourceId = $migration->getAttribute('resourceId');
        $credentials = $migration->getAttribute('credentials');
        $migrationOptions = $migration->getAttribute('options');

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
                $credentials['endpoint'] === 'http://localhost/v1' ? 'http://appwrite/v1' : $credentials['endpoint'],
                $credentials['apiKey'],
            ),
            CSV::getName() => new CSV(
                $resourceId,
                $migrationOptions['path'],
                $this->deviceForImports,
                $this->dbForProject
            ),
            default => throw new \Exception('Invalid source type'),
        };

        $this->sourceReport = $migrationSource->report();

        return $migrationSource;
    }

    /**
     * @throws Exception
     */
    protected function processDestination(Document $migration, string $apiKey): Destination
    {
        $destination = $migration->getAttribute('destination');

        return match ($destination) {
            DestinationAppwrite::getName() => new DestinationAppwrite(
                $this->project->getId(),
                'http://appwrite/v1',
                $apiKey,
                $this->dbForProject,
                Config::getParam('collections', [])['databases']['collections'],
            ),
            default => throw new \Exception('Invalid destination type'),
        };
    }

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
    {
        $errorMessages = [];
        $clonedMigrationDocument = clone $migration;

        // we cannot use #sensitive because
        // `errors` is nested which requires an override.
        $errors = $clonedMigrationDocument->getAttribute('errors', []);

        foreach ($errors as $error) {
            $decoded = json_decode($error, true);

            if (is_array($decoded) && isset($decoded['trace'])) {
                unset($decoded['trace']);
                $errorMessages[] = json_encode($decoded);
            }
        }

        // set the errors back without trace
        $clonedMigrationDocument->setAttribute('errors', $errorMessages);


        /** Trigger Realtime Events */
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('migrations.[migrationId].update')
            ->setParam('migrationId', $migration->getId())
            ->setPayload($clonedMigrationDocument->getArrayCopy(), ['options', 'credentials'])
            ->trigger();

        return $this->dbForProject->updateDocument('migrations', $migration->getId(), $migration);
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
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
            ],
            'scopes' => [
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
                'databases.read',
                'collections.read',
                'tables.read',
                'documents.read',
                'documents.write',
                'rows.read',
                'rows.write',
                'tokens.read',
                'tokens.write',
            ]
        ]);

        return API_KEY_DYNAMIC . '_' . $apiKey;
    }

    /**
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function processMigration(Document $migration, Realtime $queueForRealtime): void
    {
        $project = $this->project;
        $projectDocument = $this->dbForPlatform->getDocument('projects', $project->getId());
        $tempAPIKey = $this->generateAPIKey($projectDocument);

        $transfer = $source = $destination = null;

        try {
            if (
                $migration->getAttribute('source') === SourceAppwrite::getName() &&
                empty($migration->getAttribute('credentials', []))
            ) {
                $credentials = $migration->getAttribute('credentials', []);

                $credentials['projectId'] = $credentials['projectId'] ?? $projectDocument->getId();
                $credentials['endpoint'] = $credentials['endpoint'] ?? 'http://appwrite/v1';
                $credentials['apiKey'] = $credentials['apiKey'] ?? $tempAPIKey;

                $migration->setAttribute('credentials', $credentials);
            }

            $migration->setAttribute('stage', 'processing');
            $migration->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migration, $projectDocument, $queueForRealtime);

            $source = $this->processSource($migration);
            $destination = $this->processDestination($migration, $tempAPIKey);

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            if (empty($source->getErrors())) {
                $migration->setAttribute('stage', 'migrating');
                $this->updateMigrationDocument($migration, $projectDocument, $queueForRealtime);

                $transfer->run(
                    $migration->getAttribute('resources'),
                    function () use ($migration, $transfer, $projectDocument, $queueForRealtime) {
                        $migration->setAttribute('resourceData', json_encode($transfer->getCache()));
                        $migration->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));
                        $this->updateMigrationDocument($migration, $projectDocument, $queueForRealtime);
                    },
                    $migration->getAttribute('resourceId'),
                    $migration->getAttribute('resourceType')
                );
            }
            $destination->shutDown();
            $source->shutDown();

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();

            if (! empty($sourceErrors) || ! empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    $errorMessages[] = json_encode($error);
                }
                foreach ($destinationErrors as $error) {
                    $errorMessages[] = json_encode($error);
                }

                $migration->setAttribute('errors', $errorMessages);

                return;
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            Console::error($th->getTraceAsString());

            if (! $migration->isEmpty()) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');

                call_user_func($this->logError, $th, 'appwrite-worker', 'appwrite-queue-'.self::getName(), [
                    'migrationId' => $migration->getId(),
                    'source' => $migration->getAttribute('source') ?? '',
                    'destination' => $migration->getAttribute('destination') ?? '',
                ]);

                return;
            }

            if ($transfer) {
                $sourceErrors = $source->getErrors();
                $destinationErrors = $destination->getErrors();

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    $errorMessages[] = json_encode($error);
                }
                foreach ($destinationErrors as $error) {
                    $errorMessages[] = json_encode($error);
                }

                $migration->setAttribute('errors', $errorMessages);
            }
        } finally {
            $this->updateMigrationDocument($migration, $projectDocument, $queueForRealtime);

            if ($migration->getAttribute('status', '') === 'failed') {
                Console::error('Migration('.$migration->getSequence().':'.$migration->getId().') failed, Project('.$this->project->getSequence().':'.$this->project->getId().')');

                if ($destination) {
                    $destination->error();

                    foreach ($destination->getErrors() as $error) {
                        /** @var MigrationException $error */
                        call_user_func($this->logError, $error, 'appwrite-worker', 'appwrite-queue-' . self::getName(), [
                            'migrationId' => $migration->getId(),
                            'source' => $migration->getAttribute('source') ?? '',
                            'destination' => $migration->getAttribute('destination') ?? '',
                            'resourceName' => $error->getResourceName(),
                            'resourceGroup' => $error->getResourceGroup()
                        ]);
                    }
                }

                if ($source) {
                    $source->error();

                    foreach ($source->getErrors() as $error) {
                        /** @var MigrationException $error */
                        call_user_func($this->logError, $error, 'appwrite-worker', 'appwrite-queue-' . self::getName(), [
                            'migrationId' => $migration->getId(),
                            'source' => $migration->getAttribute('source') ?? '',
                            'destination' => $migration->getAttribute('destination') ?? '',
                            'resourceName' => $error->getResourceName(),
                            'resourceGroup' => $error->getResourceGroup()
                        ]);
                    }
                }
            }

            if ($migration->getAttribute('status', '') === 'completed') {
                $destination?->success();
                $source?->success();
            }
        }
    }
}
