<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Retention;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

final class RetentionTest extends TestCase
{
    private Database $database;

    private \DateTime $now;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_retention')
            ->setAuthorization($authorization);
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: 'deployments',
            attributes: [Attribute::string(key: 'status', size: 16)],
        ));

        $this->now = new \DateTime();
    }

    /**
     * An override may handle one reason without deleting rows, so a clamped request must not hand the deployments
     * past the request to the policy pass as well.
     */
    public function testClampedRequestSplitsDeploymentsBetweenRequestAndPolicy(): void
    {
        $this->createDeployment('beyondRequest', 200);
        $this->createDeployment('beyondMaximum', 40);
        $this->createDeployment('withinMaximum', 20);

        $this->assertSame(
            ['requested' => ['beyondRequest'], 'policy' => ['beyondMaximum']],
            $this->retire(new Retention(days: 30, maxDays: 30), requested: 180),
        );
    }

    public function testCountBreaksACreatedAtTieByInsertionOrder(): void
    {
        $this->createDeployment('newest', 1);
        $this->createDeployment('newer', 2);
        $this->createDeployment('tiedFirst', 3);
        $this->createDeployment('tiedSecond', 3);
        $this->createDeployment('oldest', 4);

        $first = $this->database->getDocument('deployments', 'tiedFirst');
        $second = $this->database->getDocument('deployments', 'tiedSecond');
        $this->assertSame($first->getCreatedAt(), $second->getCreatedAt(), 'both deployments sit on the count boundary');
        $this->assertLessThan((int) $second->getSequence(), (int) $first->getSequence());

        $this->assertSame(['count' => ['oldest', 'tiedFirst']], $this->retire(new Retention(maxCount: 3), requested: 0));
    }

    /**
     * Builds in progress must not crowd out rollback targets: the newest finished deployments keep their slots, and
     * builds are neither counted nor retired.
     */
    public function testCountSkipsBuildsInProgress(): void
    {
        $this->createDeployment('waiting', 0.5, status: 'waiting');
        $this->createDeployment('building', 0.6, status: 'building');
        $this->createDeployment('newest', 1);
        $this->createDeployment('processing', 2, status: 'processing');
        $this->createDeployment('newer', 3);
        $this->createDeployment('beyondCap', 4);
        $this->createDeployment('stuck', 5, status: 'building');
        $this->createDeployment('oldest', 6, status: 'failed');

        $this->assertSame(['count' => ['beyondCap', 'oldest']], $this->retire(new Retention(maxCount: 2), requested: 0));
    }

    public function testCountLeavesDeploymentsPastTheAgeCutoffToTheAgePass(): void
    {
        foreach ([1, 2, 3, 10, 40, 50] as $age) {
            $this->createDeployment('day' . $age, $age);
        }

        $this->assertSame(
            ['policy' => ['day40', 'day50'], 'count' => ['day10', 'day3']],
            $this->retire(new Retention(days: 30, maxCount: 2), requested: 0),
        );
    }

    public function testCountLookupFailureFollowsTheAgePasses(): void
    {
        $this->database = (new CountLookupFailingDatabase(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_retention_failing')
            ->setAuthorization($this->database->getAuthorization());
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: 'deployments',
            attributes: [Attribute::string(key: 'status', size: 16)],
        ));
        $this->createDeployment('expired', 40);

        $reasons = [];
        try {
            foreach ((new Retention(days: 30, maxCount: 1))->passes($this->database, [], 0, $this->now) as $pass) {
                $reasons[] = $pass->reason->value;
            }
            $this->fail('the count lookup failure must reach the caller');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Deployment count cutoff lookup timed out', $exception->getMessage());
        }

        $this->assertSame(['policy'], $reasons, 'the age passes are handed out before the count lookup');
    }

    /**
     * Runs every pass and returns the deployment IDs each reason selects.
     *
     * @return array<string, list<string>>
     */
    private function retire(Retention $retention, int $requested): array
    {
        $selected = [];
        foreach ($retention->passes($this->database, [], $requested, $this->now) as $pass) {
            $this->assertArrayNotHasKey($pass->reason->value, $selected, 'each reason has one pass');
            $deployments = $this->database->find('deployments', [...$pass->queries, Query::limit(100)]);
            $ids = \array_map(fn (Document $deployment): string => $deployment->getId(), $deployments);
            \sort($ids);
            $selected[$pass->reason->value] = $ids;
        }

        $all = \array_merge(...\array_values($selected));
        $this->assertSame(\array_values(\array_unique($all)), $all, 'no deployment is retired for two reasons');

        return $selected;
    }

    private function createDeployment(string $id, float $days, string $status = 'ready'): void
    {
        $createdAt = DateTime::addSeconds(clone $this->now, (int) (-$days * 86400));

        $this->database->withPreserveDates(true, fn (): Document => $this->database->createDocument('deployments', new Document([
            '$id' => $id,
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'status' => $status,
        ])));
    }
}
