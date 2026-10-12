<?php

namespace Appwrite\Databases;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Utopia\Console;
use Utopia\Database\Document;

/**
 * Queues the deletion of the logs of a transaction whose outcome is decided (committed or rolled back). It runs
 * after the outcome is stored, so it never fails the request: a deletion that cannot be queued is logged, and the
 * maintenance task deletes the transaction and its logs once it expires.
 */
final readonly class TransactionCleanup
{
    public function __construct(private DeletePublisher $publisher)
    {
    }

    public function queue(Document $project, Document $transaction): bool
    {
        try {
            if ($this->publisher->enqueue(new DeleteMessage(project: $project, type: DELETE_TYPE_DOCUMENT, document: $transaction)) !== false) {
                return true;
            }
            $reason = 'the broker refused it';
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
        }

        Console::warning("Failed to queue the deletion of transaction {$transaction->getId()}, left for the expired transaction cleanup: {$reason}");

        return false;
    }
}
