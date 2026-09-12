<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Event\Publisher\Certificate;
use Appwrite\Platform\Tasks\Interval;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Query;

final class IntervalTest extends TestCase
{
    public function testDomainVerificationRetriesRulesOfAnyAge(): void
    {
        foreach ($this->domainVerificationQueries() as $query) {
            $this->assertNotSame('$createdAt', $query->getAttribute(), 'A rule stuck on DNS verification must stay in the scan however old it is');
        }
    }

    public function testDomainVerificationRetriesOldestAttemptsFirst(): void
    {
        $queries = $this->domainVerificationQueries();

        $order = $this->queryOfMethod($queries, Query::TYPE_ORDER_ASC);
        $this->assertInstanceOf(Query::class, $order);
        $this->assertSame('$updatedAt', $order->getAttribute());

        $limit = $this->queryOfMethod($queries, Query::TYPE_LIMIT);
        $this->assertInstanceOf(Query::class, $limit);
        $this->assertSame(100, $limit->getValue());
    }

    /**
     * @return array<Query>
     */
    private function domainVerificationQueries(): array
    {
        $captured = [];

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('find')->willReturnCallback(
            function (string $collection, array $queries = []) use (&$captured): array {
                $captured = $queries;

                return [];
            }
        );

        (new IntervalTestTasks())->exposeDomainVerification($dbForPlatform, $this->createStub(Certificate::class));

        return $captured;
    }

    /**
     * @param array<Query> $queries
     */
    private function queryOfMethod(array $queries, string $method): ?Query
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === $method) {
                return $query;
            }
        }

        return null;
    }
}

final class IntervalTestTasks extends Interval
{
    public function exposeDomainVerification(Database $dbForPlatform, Certificate $publisherForCertificates): void
    {
        foreach ($this->getTasks() as $task) {
            if ($task['name'] === 'domainVerification') {
                $task['callback']($dbForPlatform, fn () => null, $publisherForCertificates);
            }
        }
    }
}
