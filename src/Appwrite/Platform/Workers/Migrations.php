<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Permission;
use Appwrite\Role;
use Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Migration\Destinations\Appwrite as DestinationsAppwrite;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Migrations extends Action
{
    private ?Database $dbForProject = null;
    private ?Database $dbForConsole = null;

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
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForProject, Database $dbForConsole, Log $log) => $this->action($message, $dbForProject, $dbForConsole, $log));
    }

    /**
     * @param Message $message
     * @param Database $dbForProject
     * @param Database $dbForConsole
     * @param Log $log
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Database $dbForProject, Database $dbForConsole, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events    = $payload['events'] ?? [];
        $project   = new Document($payload['project'] ?? []);
        $migration = new Document($payload['migration'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        $this->dbForProject = $dbForProject;
        $this->dbForConsole = $dbForConsole;

        /**
         * Handle Event execution.
         */
        if (! empty($events)) {
            return;
        }

        $log->addTag('projectId', $project->getId());

        $this->processMigration($project, $migration, $log);
    }

    /**
     * @param string $source
     * @param array $credentials
     * @return Source
     * @throws Exception
     */
    protected function processSource(string $source, array $credentials): Source
    {
        return match ($source) {
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
            Appwrite::getName() => new Appwrite($credentials['projectId'], str_starts_with($credentials['endpoint'], 'http://localhost/v1') ? 'http://appwrite/v1' : $credentials['endpoint'], $credentials['apiKey']),
            default => throw new \Exception('Invalid source type'),
        };
    }

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project): Document
    {
        /** Trigger Realtime */
        $allEvents = Event::generateEvents('migrations.[migrationId].update', [
            'migrationId' => $migration->getId(),
        ]);

        $target = Realtime::fromPayload(
            event: $allEvents[0],
            payload: $migration,
            project: $project
        );

        Realtime::send(
            projectId: 'console',
            payload: $migration->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
        );

        Realtime::send(
            projectId: $project->getId(),
            payload: $migration->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
        );

        return $this->dbForProject->updateDocument($migration->getCollection(), $migration->getId(), $migration);
    }

    /**
     * @param Document $apiKey
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     */
    protected function removeAPIKey(Document $apiKey): void
    {
        $this->dbForConsole->deleteDocument('keys', $apiKey->getId());
    }

    /**
     * @param Document $project
     * @return Document
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function generateAPIKey(Document $project): Document
    {
        $generatedSecret = bin2hex(\random_bytes(128));

        $key = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'name' => 'Transfer API Key',
            'scopes' => [
                'users.read',
                'users.write',
                'teams.read',
                'teams.write',
                'databases.read',
                'databases.write',
                'collections.read',
                'collections.write',
                'documents.read',
                'documents.write',
                'buckets.read',
                'buckets.write',
                'files.read',
                'files.write',
                'functions.read',
                'functions.write',
            ],
            'expire' => null,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => $generatedSecret,
        ]);

        $this->dbForConsole->createDocument('keys', $key);
        $this->dbForConsole->purgeCachedDocument('projects', $project->getId());

        return $key;
    }

    /**
     * @param Document $project
     * @param Document $migration
     * @param Log $log
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws \Utopia\Database\Exception
     */
    protected function processMigration(Document $project, Document $group, Log $log): void
    {
        /**
         * @var Document $migration
         * @var Transfer $transfer
         */
        $groupDocument = null;
        $transfer = null;
        $projectDocument = $this->dbForConsole->getDocument('projects', $project->getId());
        $tempAPIKey = $this->generateAPIKey($projectDocument);

        try {
            $group = $this->dbForProject->getDocument('groupMigrations', $group->getId());

            $migration = $this->dbForProject->getDocument('migrations', $group->getAttribute('migrationId', ''));
            $migration->setAttribute('status', 'processing');

            $this->updateMigrationDocument($migration, $projectDocument);

            $log->addTag('type', $migration->getAttribute('source'));

            $source = $this->processSource($migration->getAttribute('source'), $migration->getAttribute('credentials'));

            $source->report();

            $destination = new DestinationsAppwrite(
                $projectDocument->getId(),
                'http://appwrite/v1',
                $tempAPIKey['secret'],
            );

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            $this->updateMigrationDocument($migration, $projectDocument);

            // Calculate group resources
            $resources = $group->getAttribute('resources');

            switch ($group->getAttribute('group')) {
                case Transfer::GROUP_AUTH:
                    $resources = array_intersect(Transfer::GROUP_AUTH_RESOURCES, $resources);
                    break;
                case Transfer::GROUP_STORAGE:
                    $resources = array_intersect(Transfer::GROUP_STORAGE_RESOURCES, $resources);
                    break;
                case Transfer::GROUP_DATABASES:
                    $resources = array_intersect(Transfer::GROUP_DATABASES_RESOURCES, $resources);
                    break;
                case Transfer::GROUP_FUNCTIONS:
                    $resources = array_intersect(Transfer::GROUP_FUNCTIONS_RESOURCES, $resources);
                    break;
                default:
                    throw new Exception('Migration worker was initialized with unknown group');
            }

            $log->addTag('migrationGroup', $group->getAttribute('group'));
            $log->addExtra('migrationResources', json_encode($resources));

            $transfer->run($resources, function () use ($group, $transfer, $projectDocument) {
                $group->setAttribute('resourceData', json_encode($transfer->getCache()));
                $group->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));

                $this->updateMigrationDocument($group, $projectDocument);
            });

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();

            if (!empty($sourceErrors) || !empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while fetching '{$error->getResourceType()}:{$error->getResourceId()}' from source with message: '{$error->getMessage()}'";
                }
                foreach ($destinationErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while pushing '{$error->getResourceType()}:{$error->getResourceId()}' to destination with message: '{$error->getMessage()}'";
                }

                $group->setAttribute('errors', $errorMessages);
                $this->updateMigrationDocument($group, $projectDocument);
                $this->updateMigrationDocument($migration, $projectDocument);

                return;
            }

            $group->setAttribute('status', 'completed');
            $this->updateMigrationDocument($group, $project);
            
            // Check if all other groups are finished, if so set parent document to completed aswell.
            $groupDocuments = $this->dbForProject->find('groupMigrations', [Query::equal('migrationId', [$migration->getId()])]);

            $result = 'completed';
            foreach ($groupDocuments as $document) {
                if ($document->getId() == $group->getId()) {
                    continue;
                }

                $status = $document->getAttribute('status', 'processing');

                if ($status == 'processing' || $status == 'pending') {
                    $result = 'processing';
                    break;
                }

                // Only fail parent if all have stopped processing.
                if ($status == 'failed') {
                    break;
                }
            }

            $migration->setAttribute('status', $result);
            $this->updateMigrationDocument($migration, $project);
        } catch (\Throwable $th) {
            Console::error($th->getMessage());

            if ($migration && $groupDocument) {
                Console::error($th->getMessage());
                Console::error($th->getTraceAsString());
                $migration->setAttribute('status', 'failed');
                $groupDocument->setAttribute('errors', [$th->getMessage()]);

                return;
            }

            if ($transfer) {
                $sourceErrors = $source->getErrors();
                $destinationErrors = $destination->getErrors();

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while fetching '{$error->getResourceType()}:{$error->getResourceId()}' from source with message '{$error->getMessage()}'";
                }
                foreach ($destinationErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while pushing '{$error->getResourceType()}:{$error->getResourceId()}' to destination with message '{$error->getMessage()}'";
                }

                $group->setAttribute('errors', $errorMessages);
            }
        } finally {
            if ($tempAPIKey) {
                $this->removeAPIKey($tempAPIKey);
            }
            if ($migration) {
                $this->updateMigrationDocument($migration, $projectDocument);
                $this->updateMigrationDocument($group, $projectDocument);

                if ($migration->getAttribute('status', '') == 'failed') {
                    throw new Exception(implode("\n", $migration->getAttribute('errors', [])));
                }
            }
        }
    }
}
