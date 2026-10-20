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
     * Runs every pass without deleting, as an override that only records reasons would, and returns the deployment
     * IDs each reason selects.
     *
     * @return array<string, list<string>>
     */
    private function retire(Retention $retention, int $requested): array
    {
        $passes = $retention->ages($requested, $this->now);

        $cutoff = $retention->cutoff();
        if ($cutoff !== []) {
            $found = $this->database->find('deployments', $cutoff);
            if ($found !== []) {
                $passes[] = $retention->count($found[0], $requested, $this->now);
            }
        }

        $selected = [];
        foreach ($passes as $pass) {
            $this->assertArrayNotHasKey($pass->reason->value, $selected, 'each reason has one pass');
            $ids = \array_map(
                fn (Document $deployment): string => $deployment->getId(),
                $this->database->find('deployments', [...$pass->queries, Query::limit(100)]),
            );
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
