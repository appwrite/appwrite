<?php

use Appwrite\Resque\Worker;
use MongoDB\Operation\Update;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Transfer\Destinations\Appwrite as DestinationsAppwrite;
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
    private Database $dbForConsole;

    public function getName(): string
    {
        return "transfers";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        var_dump("Test");

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
        $source = $this->args['source'];

        switch ($source['type']) {
            case 'firebase':
                return new Firebase(
                    $source['authObject'] ?? '',
                    Firebase::AUTH_SERVICEACCOUNT
                );
                break;
            case 'supabase':
                return new Supabase(
                    $source['host'] ?? '',
                    $source['databaseName'] ?? '',
                    $source['username'] ?? '',
                    $source['password'] ?? '',
                    $source['port'] ?? 5432,
                );
                break;
            case 'nhost':
                return new NHost(
                    $source['host'] ?? '',
                    $source['databaseName'] ?? '',
                    $source['username'] ?? '',
                    $source['password'] ?? '',
                    $source['port'] ?? 5432,
                );
                break;
            case 'appwrite':
                return new Appwrite($source['projectId'], $source['endpoint'], $source['key']);
                break;
            default:
                throw new \Exception('Invalid source type');
                break;
        }
    }

    protected function updateAttribute(string $attribute, mixed $value, Document $document): void
    {
        $document->setAttribute($attribute, $value);
        $this->dbForConsole->updateDocument($document->getCollection(), $document->getId(), $document);
    }

    /**
     * Process Transfer
     *
     * @return void
     */
    protected function processTransfer(): void
    {
        $transferDocument = null;

        try {
            $transferDocument = $this->dbForConsole->getDocument('transfers', $this->args['transferId']);
            $this->updateAttribute('status', 'processing', $transferDocument);

            $source = $this->processSource();
            $destination = new DestinationsAppwrite($this->args['projectId'], $this->args['endpoint'], $this->args['key']);

            $transfer = new \Utopia\Transfer\Transfer(
                $source,
                $destination
            );

            $this->updateAttribute('stage', 'source-check', $transferDocument);
            if (!$source->check()) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('errorData', $transfer->getLogs('error'));
            }

            $this->updateAttribute('stage', 'destination-check', $transferDocument);
            if (!$destination->check()) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('stage', 'destination-check');
                $transferDocument->setAttribute('errorData', $transfer->getLogs('error'));
            }

            /** Start Transfer */
            $transfer->run($this->args['resoruces'], function (Update $update) use ($transferDocument, $transfer, $source, $destination) {
                $transferDocument->setAttribute('stage', 'transfer');
                $transferDocument->setAttribute('latestUpdate', json_encode($update));
                $transferDocument->setAttribute('progress', json_encode([
                    'source' => $source->getCounter(''),
                    'destination' => $destination->getCounter(''),
                ]));
                $this->dbForConsole->updateDocument($transferDocument->getCollection(), $transferDocument->getId(), $transferDocument);
            });

            if (!empty($transfer->getLogs('error'))) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('errorData', $transfer->getLogs('error'));
            }

            $transferDocument->setAttribute('status', 'completed');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            if ($transferDocument) {
                $transferDocument->setAttribute('status', 'failed');
                $transferDocument->setAttribute('errorData', $transfer->getLogs('error'));
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
}
