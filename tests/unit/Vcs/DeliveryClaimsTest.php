<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\DeliveryClaim;
use Appwrite\Vcs\DeliveryClaims;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;

final class DeliveryClaimsTest extends TestCase
{
    /** @var array<string, Document> The lock documents, by id */
    private array $locks = [];

    /** Seconds from now that a document created next is dated */
    private int $clock = 0;

    public function testAFreshClaimIsTaken(): void
    {
        $claim = $this->claims()->take('delivery:push:main:repository');

        $this->assertSame(DeliveryClaim::TAKEN, $claim->status);
        $this->assertNull($claim->resumedFrom);
        $this->assertArrayHasKey($claim->id, $this->locks);
    }

    public function testARetryWhileAnotherAttemptHoldsTheWorkIsBusy(): void
    {
        $this->claims()->take('work');

        $this->assertSame(DeliveryClaim::BUSY, $this->claims()->take('work')->status, 'The attempt holding the work can still fail, so a retry must not be acknowledged');
    }

    public function testFinishedWorkIsDone(): void
    {
        $claims = $this->claims();
        $claims->finish($claims->take('work'));

        $this->assertSame(DeliveryClaim::DONE, $claims->take('work')->status);
    }

    public function testWorkGivenBackIsTakenAgain(): void
    {
        $claims = $this->claims();
        $claims->release($claims->take('work'));

        $claim = $claims->take('work');
        $this->assertSame(DeliveryClaim::TAKEN, $claim->status);
        $this->assertNull($claim->resumedFrom);
    }

    public function testAnAbandonedClaimIsTakenOverFromWhenItsWorkBegan(): void
    {
        $this->clock = -DeliveryClaims::ABANDONED_AFTER - 60;
        $abandoned = $this->claims()->take('work');
        $this->clock = 0;

        $claim = $this->claims()->take('work');

        $this->assertSame(DeliveryClaim::TAKEN, $claim->status);
        $this->assertSame($this->locks[$abandoned->id]->getCreatedAt(), $claim->resumedFrom, 'The attempt that stopped may have deployed since it began');
        $this->assertNotSame($abandoned->id, $claim->id);
    }

    public function testOnlyOneRetryTakesOverAnAbandonedClaim(): void
    {
        $this->clock = -DeliveryClaims::ABANDONED_AFTER - 60;
        $this->claims()->take('work');
        $this->clock = 0;

        $this->assertSame(DeliveryClaim::TAKEN, $this->claims()->take('work')->status);
        $this->assertSame(DeliveryClaim::BUSY, $this->claims()->take('work')->status);
    }

    public function testATakenOverClaimGivenBackIsTakenOverAgainFromTheSameStart(): void
    {
        $this->clock = -DeliveryClaims::ABANDONED_AFTER - 60;
        $abandoned = $this->claims()->take('work');
        $this->clock = 0;

        $claims = $this->claims();
        $claims->release($claims->take('work'));
        $claim = $claims->take('work');

        $this->assertSame(DeliveryClaim::TAKEN, $claim->status);
        $this->assertSame($this->locks[$abandoned->id]->getCreatedAt(), $claim->resumedFrom);
    }

    public function testWorkTakenOverIsDoneOnceFinished(): void
    {
        $this->clock = -DeliveryClaims::ABANDONED_AFTER - 60;
        $this->claims()->take('work');
        $this->clock = 0;

        $claims = $this->claims();
        $claims->finish($claims->take('work'));

        $this->assertSame(DeliveryClaim::DONE, $claims->take('work')->status);
    }

    public function testEachPieceOfWorkIsClaimedOnItsOwn(): void
    {
        $claims = $this->claims();

        $this->assertSame(DeliveryClaim::TAKEN, $claims->take('delivery:push:main:repository')->status);
        $this->assertSame(DeliveryClaim::TAKEN, $claims->take('delivery:push:staging:repository')->status);
    }

    public function testEveryIdFitsADocumentId(): void
    {
        foreach (['origin', 'entire', 'bitbucket'] as $provider) {
            $claims = $this->claims($provider);
            $claims->finish($claims->take(\str_repeat('w', 500)));
        }

        foreach (\array_keys($this->locks) as $id) {
            $this->assertLessThanOrEqual(36, \strlen($id), $id);
        }
        $this->assertCount(6, $this->locks);
    }

    private function claims(string $provider = 'origin'): DeliveryClaims
    {
        $db = $this->createStub(Database::class);
        $db->method('getDocument')->willReturnCallback(fn (string $collection, string $id): Document => $this->locks[$id] ?? new Document());
        $db->method('createDocument')->willReturnCallback(function (string $collection, Document $document): Document {
            if (isset($this->locks[$document->getId()])) {
                throw new Duplicate('Document already exists');
            }
            $document->setAttribute('$createdAt', DateTime::addSeconds(new \DateTime(), $this->clock));

            return $this->locks[$document->getId()] = $document;
        });
        $db->method('deleteDocument')->willReturnCallback(function (string $collection, string $id): bool {
            unset($this->locks[$id]);

            return true;
        });

        return new DeliveryClaims($db, new Authorization(), $provider);
    }
}
