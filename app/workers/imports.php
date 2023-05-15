<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\Database\Helpers\ID;
use Appwrite\Permission;
use Appwrite\Query;
use Appwrite\Resque\Worker;
use Appwrite\Role;
use Appwrite\Utopia\Response\Model\Import;
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

Console::title('Imports V1 Worker');
Console::success(APP_NAME . ' Imports worker v1 has started');

class ImportsV1 extends Worker
{
    /**
     * Database connection shared across all methods of this file
     *
     * @var Database
     */
    private Database $dbForProject;

    public function getName(): string
    {
        return "imports";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $this->dbForProject = $this->getProjectDB($this->args['project']['$id']);

        // Process
        $this->processImport();
    }

    /**
     * Process Source
     *
     * @return Source
     * @throws \Exception
     */
    function processSource(array $source): Source
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
                    $source['key'],
                    $source['host'],
                    $source['database'],
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

    protected function updateImportDocument(Document $import, Document $project): Document
    {
        // Trigger Webhook
        $importModel = new Import();

        $importUpdate = new Event(Event::IMPORTS_QUEUE_NAME, Event::IMPORTS_CLASS_NAME);
        $importUpdate
            ->setProject($project)
            ->setEvent('imports.[importId].update')
            ->setParam('importId', $import->getId())
            ->setPayload($import->getArrayCopy(array_keys($importModel->getRules())))
            ->trigger();

        /** Trigger Realtime */
        $allEvents = Event::generateEvents('imports.[importId].update', [
            'importId' => $import->getId(),
        ]);

        $target = Realtime::fromPayload(
            event: $allEvents[0],
            payload: $import,
            project: $project
        );

        Realtime::send(
            projectId: 'console',
            payload: $import->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
        );

        return $this->dbForProject->updateDocument('imports', $import->getId(), $import);
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
                // Auth
                'users.read',
                'users.write',
                'teams.read',
                'teams.write',
                // Database
                'databases.read',
                'databases.write',
                'collections.read',
                'collections.write',
                'documents.read',
                'documents.write',
                // Storage
                'buckets.read',
                'buckets.write',
                'files.read',
                'files.write',

                // Functions
                'functions.read',
                'functions.write',
            ],
            'expire' => null,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => $generatedSecret,
        ]);

        return $consoleDB->createDocument('keys', $key);
    }

    /**
     * Process Import
     *
     * @return void
     */
    protected function processImport(): void
    {
        /**
         * @var Document $importDocument
         * @var Transfer $transfer
         */
        $importDocument = null;
        $transfer = null;
        $projectDocument = $this->dbForProject->getDocument('projects', $this->args['project']['$id']);
        $tempAPIKey = $this->generateAPIKey($projectDocument);

        try {
            $importDocument = $this->dbForProject->getDocument('imports', $this->args['import']['$id']);
            $importDocument->setAttribute('status', 'processing');
            $this->updateImportDocument($importDocument, $projectDocument);

            $source = $this->processSource(json_decode($importDocument->getAttribute('source'), true));

            $destination = new DestinationsAppwrite(
                $projectDocument->getId(),
                'http://appwrite/v1',
                $tempAPIKey['secret'],
            );

            $transfer = new Transfer(
                $source,
                $destination
            );

            $importDocument->setAttribute('status', 'source-check');
            $this->updateImportDocument($importDocument, $projectDocument);
            $source->report();

            $importDocument->setAttribute('status', 'destination-check');
            $this->updateImportDocument($importDocument, $projectDocument);
            $destination->report();

            /** Start Transfer */
            $importDocument->setAttribute('status', 'importing');
            $this->updateImportDocument($importDocument, $projectDocument);
            $transfer->run($importDocument->getAttribute('resources'), function () use ($importDocument, $transfer, $projectDocument) {
                $importDocument->setAttribute('resourceData', json_encode($transfer->getResourceCache()));
                $importDocument->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));

                $this->updateImportDocument($importDocument, $projectDocument);
            });

            $errors = $transfer->getReport(Resource::STATUS_ERROR);

            if (count($errors) > 0) {
                $importDocument->setAttribute('status', 'failed');
                $importDocument->setAttribute('errorData', $errors);
                $this->updateImportDocument($importDocument, $projectDocument);
                return;
            }
            
            $importDocument->setAttribute('status', 'completed');
            $importDocument->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());

            if ($importDocument) {
                Console::error($th->getMessage());
                Console::error($th->getTraceAsString());
                $importDocument->setAttribute('status', 'failed');
                $importDocument->setAttribute('errorData', $th->getMessage());
                return;
            }
        } finally {
            if ($importDocument) {
                $this->updateImportDocument($importDocument, $projectDocument);
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
