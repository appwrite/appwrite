<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\Database\Helpers\ID;
use Appwrite\Permission;
use Appwrite\Query;
use Appwrite\Resque\Worker;
use Appwrite\Role;
use Appwrite\Utopia\Response\Model\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Transfer\Destination;
use Utopia\Transfer\Destinations\Appwrite as DestinationsAppwrite;
use Utopia\Transfer\Resource;
use Utopia\Transfer\Source;
use Utopia\Transfer\Sources\Appwrite;
use Utopia\Transfer\Sources\Firebase;
use Utopia\Transfer\Sources\NHost;
use Utopia\Transfer\Sources\Supabase;
use Utopia\Transfer\Transfer;

require_once __DIR__ . '/../init.php';

Console::title('Migrations V1 Worker');
Console::success(APP_NAME . ' Migrations worker v1 has started');

class MigrationsV1 extends Worker
{
    /**
     * Database connection shared across all methods of this file
     *
     * @var Database
     */
    private Database $dbForProject;

    public function getName(): string
    {
        return "migrations";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $events = $this->args['events'] ?? [];
        $project = new Document($this->args['project'] ?? []);
        $user = new Document($this->args['user'] ?? []);
        $payload = json_encode($this->args['payload'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }


        /**
         * Handle Event execution.
         */
        if (!empty($events)) {
            return;
        }

        $this->dbForProject = $this->getProjectDB($this->args['project']['$id']);

        // Process
        $this->processMigration();
    }

    /**
     * Process Source
     *
     * @return Source
     * @throws \Exception
     */
    protected function processSource(array $source): Source
    {
        switch ($source['type']) {
            case 'firebase':
                return new Firebase(
                    json_decode($source['serviceAccount'], true),
                );
                break;
            case 'supabase':
                return new Supabase(
                    $source['endpoint'],
                    $source['apiKey'],
                    $source['databaseHost'],
                    "postgres",
                    $source['username'],
                    $source['password'],
                    $source['port'],
                );
                break;
            case 'nhost':
                return new NHost(
                    $source['subdomain'],
                    $source['region'],
                    $source['adminSecret'],
                    $source['database'],
                    $source['username'],
                    $source['password'],
                    $source['port'],
                );
                break;
            case 'appwrite':
                return new Appwrite($source['projectId'], $source['endpoint'], $source['apiKey']);
                break;
            default:
                throw new \Exception('Invalid source type');
                break;
        }
    }

    protected function updateMigrationDocument(Document $migration, Document $project): Document
    {
        // Trigger Webhook
        $migrationModel = new Migration();

        $migrationUpdate = new Event(Event::MIGRATIONS_QUEUE_NAME, Event::MIGRATIONS_CLASS_NAME);
        $migrationUpdate
            ->setProject($project)
            ->setEvent('migrations.[migrationId].update')
            ->setParam('migrationId', $migration->getId())
            ->setPayload($migration->getArrayCopy(array_keys($migrationModel->getRules())))
            ->trigger();

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

        return $this->dbForProject->updateDocument('migrations', $migration->getId(), $migration);
    }

    protected function removeAPIKey(Document $apiKey)
    {
        $consoleDB = $this->getConsoleDB();

        $consoleDB->deleteDocument('keys', $apiKey->getId());
    }

    protected function generateAPIKey(Document $project): Document
    {
        $consoleDB = $this->getConsoleDB();
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

        $consoleDB->createDocument('keys', $key);
        $consoleDB->deleteCachedDocument('projects', $project->getId());

        return $key;
    }

    /**
     * Process Migration
     *
     * @return void
     */
    protected function processMigration(): void
    {
        /**
         * @var Document $migrationDocument
         * @var Transfer $transfer
         */
        $migrationDocument = null;
        $transfer = null;
        $projectDocument = $this->getConsoleDB()->getDocument('projects', $this->args['project']['$id']);
        $tempAPIKey = $this->generateAPIKey($projectDocument);

        try {
            $migrationDocument = $this->dbForProject->getDocument('migrations', $this->args['migration']['$id']);
            $migrationDocument->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migrationDocument, $projectDocument);

            $source = $this->processSource(json_decode($migrationDocument->getAttribute('source'), true));

            $destination = new DestinationsAppwrite(
                $projectDocument->getId(),
                'http://appwrite/v1',
                $tempAPIKey['secret'],
            );

            $transfer = new Transfer(
                $source,
                $destination
            );

            $migrationDocument->setAttribute('status', 'source-check');
            $this->updateMigrationDocument($migrationDocument, $projectDocument);
            $source->report();

            $migrationDocument->setAttribute('status', 'destination-check');
            $this->updateMigrationDocument($migrationDocument, $projectDocument);
            $destination->report();

            /** Start Transfer */
            $migrationDocument->setAttribute('status', 'migrating');
            $this->updateMigrationDocument($migrationDocument, $projectDocument);
            $transfer->run($migrationDocument->getAttribute('resources'), function () use ($migrationDocument, $transfer, $projectDocument) {
                $migrationDocument->setAttribute('resourceData', json_encode($transfer->getResourceCache()));
                $migrationDocument->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));

                $this->updateMigrationDocument($migrationDocument, $projectDocument);
            });

            $errors = $transfer->getReport(Resource::STATUS_ERROR);

            if (count($errors) > 0) {
                $migrationDocument->setAttribute('status', 'failed');
                $migrationDocument->setAttribute('errorData', $errors);
                $this->updateMigrationDocument($migrationDocument, $projectDocument);
                return;
            }

            $migrationDocument->setAttribute('status', 'completed');
            $migrationDocument->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());

            if ($migrationDocument) {
                Console::error($th->getMessage());
                Console::error($th->getTraceAsString());
                $migrationDocument->setAttribute('status', 'failed');
                $migrationDocument->setAttribute('errorData', $th->getMessage());
                return;
            }
        } finally {
            if ($migrationDocument) {
                $this->updateMigrationDocument($migrationDocument, $projectDocument);
            }
            if ($tempAPIKey) {
                $this->removeAPIKey($tempAPIKey);
            }
        }
    }

    /**
     * Process Verification
     *
     * @return void
     */
    protected function processVerification(): void
    {
    }

    public function shutdown(): void
    {
    }
}
