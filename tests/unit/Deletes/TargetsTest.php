<?php

declare(strict_types=1);

namespace Tests\Unit\Deletes;

use Appwrite\Deletes\Targets;
use PHPUnit\Framework\TestCase;
use Throwable;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Validator\Authorization;

final class TargetsTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        // Workers run with authorization disabled, as app/worker.php sets it up.
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_1')
            ->setAuthorization($authorization);

        $this->database->create();
    }

    /**
     * A project database created before `targets` existed never got it. The sweep has
     * nothing to delete there, and must not fail the run that reaches it.
     */
    public function testDeletingExpiredTargetsSkipsADatabaseWithoutTheCollection(): void
    {
        $this->assertFalse(Targets::deleteExpired($this->database));
        $this->assertNull($this->database->findCollection('targets'));
    }

    public function testDeletingExpiredTargetsKeepsTheUnexpired(): void
    {
        $this->createTargets();
        $this->createSubscribers();

        $this->assertTrue(Targets::deleteExpired($this->database));

        $this->assertTrue($this->database->getDocument('targets', 'expired')->isEmpty());
        $this->assertFalse($this->database->getDocument('targets', 'active')->isEmpty());
    }

    /**
     * Only an absent `targets` is skipped. Any other failure, a missing `subscribers`
     * among them, has to fail the job so the queue retries it.
     */
    public function testDeletingExpiredTargetsFailsWhenTheirSubscribersCannotBeRemoved(): void
    {
        $this->createTargets();

        $thrown = null;
        try {
            Targets::deleteExpired($this->database);
        } catch (Throwable $th) {
            $thrown = $th;
        }

        $this->assertInstanceOf(NotFoundException::class, $thrown);
    }

    private function createTargets(): void
    {
        $this->database->createCollection(Collection::create(
            id: 'targets',
            attributes: [Attribute::boolean('expired', default: false)],
        ));

        foreach (['expired' => true, 'active' => false] as $id => $expired) {
            $this->database->createDocument('targets', new Document([
                '$id' => $id,
                'expired' => $expired,
            ]));
        }
    }

    private function createSubscribers(): void
    {
        $this->database->createCollection(Collection::create(
            id: 'subscribers',
            attributes: [Attribute::string('targetInternalId', 36)],
        ));
    }
}
