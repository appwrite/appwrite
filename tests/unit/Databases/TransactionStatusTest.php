<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionStatus;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class TransactionStatusTest extends TestCase
{
    public function testStoresTheCommittedStatus(): void
    {
        $database = $this->database(failures: 0);

        $transaction = (new TransactionStatus($database))->committed($this->stored($database));

        $this->assertSame('committed', $transaction->getAttribute('status'));
        $this->assertSame('committed', $this->stored($database)->getAttribute('status'));
    }

    public function testRetriesAStatusWriteThatFailsOnce(): void
    {
        $database = $this->database(failures: 1);

        (new TransactionStatus($database))->committed($this->stored($database));

        $this->assertSame('committed', $this->stored($database)->getAttribute('status'));
    }

    public function testAnswersCommittedWhenTheStatusCannotBeStored(): void
    {
        $database = $this->database(failures: \PHP_INT_MAX);

        $transaction = (new TransactionStatus($database))->committed($this->stored($database));

        $this->assertSame('committed', $transaction->getAttribute('status'), 'The writes landed, so the caller gets the committed outcome');
        $this->assertSame('committing', $this->stored($database)->getAttribute('status'));
    }

    public function testAnswersCommittedWhenTheTransactionWasDeletedBeforeTheStatusWrite(): void
    {
        $database = $this->database(failures: 0);
        $transaction = $this->stored($database);
        $database->getAuthorization()->skip(fn (): bool => $database->deleteDocument('transactions', 'transaction'));

        $answered = (new TransactionStatus($database))->committed($transaction);

        $this->assertSame('transaction', $answered->getId(), 'A transaction deleted by the expired-transaction cleanup is still answered');
        $this->assertSame('committed', $answered->getAttribute('status'));
        $this->assertTrue($this->stored($database)->isEmpty(), 'A deleted transaction is not recreated');
    }

    private function stored(TransactionStatusDatabase $database): Document
    {
        return $database->getAuthorization()->skip(fn (): Document => $database->getDocument('transactions', 'transaction'));
    }

    private function database(int $failures): TransactionStatusDatabase
    {
        $authorization = new Authorization();
        $database = new TransactionStatusDatabase(new Memory(), new Cache(new None()));
        $database->setAuthorization($authorization)->setDatabase('status')->setNamespace('status_' . \uniqid());
        $authorization->skip(function () use ($database): void {
            $database->create();
            $database->createCollection(Collection::create('transactions', attributes: [Attribute::string('status', size: 16)]));
            $database->createDocument('transactions', new Document(['$id' => 'transaction', 'status' => 'committing']));
        });
        $database->failures = $failures;

        return $database;
    }
}
