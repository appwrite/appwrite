<?php

namespace Appwrite\Databases;

use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * Stores the outcome of a transaction whose writes have already landed. The request must still answer that outcome
 * when the status cannot be stored: the write is retried, and if it keeps failing the caller gets the committed
 * transaction while the stored one stays `committing` until the expired-transaction cleanup deletes it.
 */
final readonly class TransactionStatus
{
    private const int ATTEMPTS = 3;

    private const int BACKOFF_MICROSECONDS = 100_000;

    public function __construct(private Database $dbForProject)
    {
    }

    public function committed(Document $transaction): Document
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $stored = $this->dbForProject->getAuthorization()->skip(fn (): Document => $this->dbForProject->updateDocument(
                    'transactions',
                    $transaction->getId(),
                    new Document(['status' => 'committed']),
                ));
            } catch (\Throwable $error) {
                if ($attempt >= self::ATTEMPTS) {
                    Console::error("Transaction {$transaction->getId()} committed, but its status could not be stored: {$error->getMessage()}");

                    return $this->answer($transaction);
                }

                \usleep(self::BACKOFF_MICROSECONDS * $attempt);

                continue;
            }

            if ($stored->isEmpty()) {
                Console::warning("Transaction {$transaction->getId()} committed, but it was deleted before its status could be stored");

                return $this->answer($transaction);
            }

            return $stored;
        }
    }

    private function answer(Document $transaction): Document
    {
        return (clone $transaction)->setAttribute('status', 'committed');
    }
}
