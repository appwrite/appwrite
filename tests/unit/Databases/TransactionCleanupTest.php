<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionCleanup;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class TransactionCleanupTest extends TestCase
{
    public function testQueuesTheDeletionOfTheTransaction(): void
    {
        $broker = $this->broker(fn (): bool => true);

        $this->assertTrue((new TransactionCleanup(new DeletePublisher($broker, new Queue('deletes'))))->queue(new Document(['$id' => 'project']), new Document(['$id' => 'transaction'])));
        $this->assertCount(1, $broker->published);
        $this->assertSame('transaction', $broker->published[0]['document']['$id']);
    }

    public function testABrokerFailureAfterTheOutcomeIsStoredDoesNotFailTheRequest(): void
    {
        $broker = $this->broker(fn (): bool => throw new \RuntimeException('Broker unavailable'));

        $this->assertFalse((new TransactionCleanup(new DeletePublisher($broker, new Queue('deletes'))))->queue(new Document(['$id' => 'project']), new Document(['$id' => 'transaction'])));
    }

    public function testABrokerRefusingTheMessageDoesNotFailTheRequest(): void
    {
        $broker = $this->broker(fn (): bool => false);

        $this->assertFalse((new TransactionCleanup(new DeletePublisher($broker, new Queue('deletes'))))->queue(new Document(['$id' => 'project']), new Document(['$id' => 'transaction'])));
    }

    /**
     * @param callable(): bool $answer
     */
    private function broker(callable $answer): TransactionCleanupBroker
    {
        return new TransactionCleanupBroker($answer);
    }
}
