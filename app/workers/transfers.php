<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Transfer\Destination;
use Utopia\Transfer\Destinations\Appwrite as DestinationsAppwrite;
use Utopia\Transfer\Progress;
use Utopia\Transfer\Source;
use Utopia\Transfer\Sources\Appwrite;
use Utopia\Transfer\Sources\Firebase;
use Utopia\Transfer\Sources\NHost;
use Utopia\Transfer\Sources\Supabase;

require_once __DIR__ . '/../init.php';

Console::title('Transfers V1 Worker');
Console::success(APP_NAME . ' Transfers worker v1 has started');

class TransfersV1 extends Worker
{
    /**
     * Database connection shared across all methods of this file
     *
     * @var Database
     */
    private Database $dbForProject;

    public function getName(): string
    {
        return "transfers";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $this->dbForProject = $this->getProjectDB($this->args['project']['$id']);

        // Process
        $this->processTransfer();
    }

    /**
     * Process Source
     *
     * @return Source
     * @throws \Exception
     */
    function processSource(): Source
    {
        $source = $this->dbForProject->getDocument('sources', $this->args['transfer']['source']);

        $authObject = json_decode($source['data'], true) ?? [];

        switch ($source['type']) {
            case 'firebase':
                return new Firebase(
                    $authObject['serviceAccount'] ?? '',
                    Firebase::AUTH_SERVICEACCOUNT
                );
                break;
            case 'supabase':
                return new Supabase(
                    $authObject['url'] ?? '',
                    $authObject['database'] ?? '',
                    $authObject['username'] ?? '',
                    $authObject['password'] ?? '',
                    $authObject['port'] ?? 5432,
                );
                break;
            case 'nhost':
                return new NHost(
                    $authObject['url'] ?? '',
                    $authObject['database'] ?? '',
                    $authObject['username'] ?? '',
                    $authObject['password'] ?? '',
                    $authObject['port'] ?? 5432,
                );
                break;
            case 'appwrite':
                return new Appwrite($authObject['projectId'], $authObject['endpoint'], $authObject['key']);
                break;
            default:
                throw new \Exception('Invalid source type');
                break;
        }
    }

    /**
     * Process Destination
     * 
     * @return Destination
     * @throws \Exception
     */
    function processDestination(): Destination
    {
        $destination = $this->dbForProject->getDocument('destinations', $this->args['transfer']['destination']);

        $authObject = json_decode($destination['data'], true) ?? [];
        

        switch ($destination['type']) {
            case 'appwrite':
                if ($authObject['endpoint'] === 'http://localhost/v1') { // Rewrite into Internal Network.
                    return new DestinationsAppwrite($authObject['projectId'], 'http://appwrite/v1', $authObject['key']);
                } else {
                    return new DestinationsAppwrite($authObject['projectId'], $authObject['endpoint'], $authObject['key']);
                }
                break;
            default:
                throw new \Exception('Invalid destination type');
                break;
        }
    }

    protected function updateAttribute(string $attribute, mixed $value, Document $document): void
    {
        $document->setAttribute($attribute, $value);
        $this->dbForProject->updateDocument($document->getCollection(), $document->getId(), $document);
    }

    /**
     * Process Transfer
     *
     * @return void
     */
    protected function processTransfer(): void
    {
        $transferDocument = null;
        $transfer = null;

        try {
            $transferDocument = $this->dbForProject->getDocument('transfers', $this->args['transfer']['$id']);
            $this->updateAttribute('status', 'processing', $transferDocument);

            $source = $this->processSource();
            $destination = $this->processDestination();

            $transfer = new \Utopia\Transfer\Transfer(
                $source,
                $destination
            );

            $this->updateAttribute('stage', 'source-check', $transferDocument);
            if (!$source->check()) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('errorData', json_encode($transfer->getLogs('error')));
            }

            $this->updateAttribute('stage', 'destination-check', $transferDocument);
            if (!$destination->check()) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('stage', 'destination-check');
                $transferDocument->setAttribute('errorData', json_encode($transfer->getLogs('error')));
            }

            /** Start Transfer */
            $transfer->run($transferDocument->getAttribute('resources'), function (Progress $progress) use ($transferDocument, $transfer, $source, $destination) {
                var_dump($progress->getProgress());
                $transferDocument->setAttribute('stage', 'transfer');
                $transferDocument->setAttribute('latestProgress', json_encode($progress));
                $transferDocument->setAttribute('totalProgress', json_encode([
                    'source' => $source->getCounter(),
                    'destination' => $destination->getCounter(),
                ]));
                $this->dbForProject->updateDocument($transferDocument->getCollection(), $transferDocument->getId(), $transferDocument);
            });

            if (!empty($transfer->getLogs('error'))) {
                $transferDocument->setAttribute('status', 'failed');

                $logs = [];

                foreach ($transfer->getLogs('error') as $log) {
                    $logs[] = $log->asArray();
                }

                var_dump($logs);

                $transferDocument->setAttribute('errorData', json_encode($logs));
            } else {
                $transferDocument->setAttribute('status', 'completed');
            }
        } catch (\Throwable $th) {
            Console::error($th->getMessage());

            // Improve Error Handler.

            if ($transferDocument) {
                $transferDocument->setAttribute('status', 'failed');

                foreach ($transfer->getLogs('error') as $log) {
                    $logs[] = $log->asArray();
                }

                var_dump($logs);

                $transferDocument->setAttribute('errorData', json_encode($logs));
            }

            throw $th;
        } finally {
            if ($transferDocument) {
                $this->dbForProject->updateDocument($transferDocument->getCollection(), $transferDocument->getId(), $transferDocument);
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
