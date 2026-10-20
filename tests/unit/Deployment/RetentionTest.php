<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Retention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;

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

    public function testRequestedDaysWinOverTheDefault(): void
    {
        $this->assertSame(7, (new Retention(days: 30))->resolve(7));
    }

    public function testDefaultAppliesWhenNothingIsRequested(): void
    {
        $this->assertSame(30, (new Retention(days: 30))->resolve(0));
    }

    public function testRequestedDaysAreClampedToTheMaximum(): void
    {
        $this->assertSame(30, (new Retention(days: 7, maxDays: 30))->resolve(180));
    }

    public function testRequestedDaysWithinTheMaximumAreKept(): void
    {
        $this->assertSame(20, (new Retention(days: 7, maxDays: 30))->resolve(20));
    }

    public function testZeroMaximumLeavesRequestedDaysUnlimited(): void
    {
        $this->assertSame(3650, (new Retention(days: 30, maxDays: 0))->resolve(3650));
    }

    public function testAllZerosKeepDeploymentsForever(): void
    {
        $this->assertSame(0, (new Retention())->resolve(0));
    }

    public function testMaximumAppliesWhenNeitherRequestNorDefaultIsSet(): void
    {
        $this->assertSame(30, (new Retention(days: 0, maxDays: 30))->resolve(0));
    }

    public function testDefaultAboveTheMaximumIsClamped(): void
    {
        $this->assertSame(30, (new Retention(days: 90, maxDays: 30))->resolve(0));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function negativeValues(): iterable
    {
        yield 'days' => [-1, 0, 0];
        yield 'maxDays' => [0, -1, 0];
        yield 'maxCount' => [0, 0, -1];
    }

    #[DataProvider('negativeValues')]
    public function testNegativeValuesAreRefused(int $days, int $maxDays, int $maxCount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Retention(days: $days, maxDays: $maxDays, maxCount: $maxCount);
    }

    public function testNothingRetiresWithoutRequestOrPolicy(): void
    {
        $this->createDeployment('ancient', 4000);

        $this->assertSame([], $this->retire(new Retention(), requested: 0));
    }

    public function testRequestRetiresDeploymentsPastIt(): void
    {
        $this->createDeployment('old', 5);
        $this->createDeployment('fresh', 0.05);

        $this->assertSame(['requested' => ['old']], $this->retire(new Retention(), requested: 1));
    }

    public function testPolicyDefaultRetiresDeploymentsPastIt(): void
    {
        $this->createDeployment('expired', 40);
        $this->createDeployment('current', 20);

        $this->assertSame(['policy' => ['expired']], $this->retire(new Retention(days: 30, maxDays: 30), requested: 0));
    }

    public function testRequestWithinTheMaximumIsTheOnlyAgePass(): void
    {
        $this->createDeployment('expired', 25);
        $this->createDeployment('current', 15);

        $this->assertSame(['requested' => ['expired']], $this->retire(new Retention(days: 7, maxDays: 30), requested: 20));
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

    public function testCountKeepsTheNewestDeployments(): void
    {
        foreach ([1, 2, 3, 4, 5] as $age) {
            $this->createDeployment('day' . $age, $age);
        }

        $this->assertSame(['count' => ['day4', 'day5']], $this->retire(new Retention(maxCount: 3), requested: 0));
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

    /**
     * Applied the way maintenance applies them, each pass deleting what it selects before the next one runs, the
     * passes leave the resource's kept deployments, those within the policy, and the newest finished ones.
     */
    public function testAppliedPassesLeaveKeptRecentAndNewestDeployments(): void
    {
        $this->createDeployment('active', 400);
        foreach ([1, 2, 3, 10, 40, 200] as $age) {
            $this->createDeployment('day' . $age, $age);
        }

        $retired = $this->retire(new Retention(days: 30, maxDays: 30, maxCount: 2), requested: 180, resource: [Query::notEqual('$id', 'active')], delete: true);

        $this->assertSame(['requested' => ['day200'], 'policy' => ['day40'], 'count' => ['day10', 'day3']], $retired);
        $this->assertSame(['active', 'day1', 'day2'], $this->survivors());
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
     * Runs every pass and returns the deployment IDs each reason selects. Without deleting, it stands in for an
     * override that only records reasons; with it, for maintenance.
     *
     * @param list<Query> $resource
     * @return array<string, list<string>>
     */
    private function retire(Retention $retention, int $requested, array $resource = [], bool $delete = false): array
    {
        $selected = [];
        foreach ($retention->passes($this->database, $resource, $requested, $this->now) as $pass) {
            $this->assertArrayNotHasKey($pass->reason->value, $selected, 'each reason has one pass');
            $deployments = $this->database->find('deployments', [...$resource, ...$pass->queries, Query::limit(100)]);
            $ids = \array_map(fn (Document $deployment): string => $deployment->getId(), $deployments);
            \sort($ids);
            $selected[$pass->reason->value] = $ids;

            if ($delete) {
                foreach ($deployments as $deployment) {
                    $this->database->deleteDocument('deployments', $deployment->getId());
                }
            }
        }

        $all = \array_merge(...\array_values($selected));
        $this->assertSame(\array_values(\array_unique($all)), $all, 'no deployment is retired for two reasons');

        return $selected;
    }

    /**
     * @return list<string>
     */
    private function survivors(): array
    {
        $ids = \array_map(
            fn (Document $deployment): string => $deployment->getId(),
            $this->database->find('deployments', [Query::limit(100)]),
        );
        \sort($ids);

        return $ids;
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

/**
 * Fails the count cap's cutoff lookup, as an engine timeout would, and serves every other read.
 */
final class CountLookupFailingDatabase extends Database
{
    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        if (\array_any($queries, fn (Query $query): bool => $query->getMethod() === Method::Offset)) {
            throw new \RuntimeException('Deployment count cutoff lookup timed out');
        }

        return parent::find($collection, $queries, $forPermission);
    }
}
