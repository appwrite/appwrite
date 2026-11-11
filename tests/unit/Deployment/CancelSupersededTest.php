<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Lock\Exception\Contention;
use Utopia\Query\Method;

final class CancelSupersededTest extends TestCase
{
    public function testCanceledAttributesHandlesStartedUnstartedAndLogTruncation(): void
    {
        $unstarted = new Document([
            '$id' => 'unstarted',
            'buildLogs' => "Cloning...\n",
            'buildStartedAt' => '',
        ]);

        $attrs = Deployments::canceledAttributes($unstarted);
        $this->assertSame('canceled', $attrs['status']);
        $this->assertSame(0, $attrs['buildDuration']);
        $this->assertNotEmpty($attrs['buildEndedAt']);
        $this->assertStringStartsWith("Cloning...\n", $attrs['buildLogs']);
        $this->assertStringContainsString('Build has been canceled.', $attrs['buildLogs']);

        $startedAt = (new \DateTimeImmutable('-15 seconds'))->format('Y-m-d H:i:s.v');
        $started = new Document([
            '$id' => 'started',
            'buildStartedAt' => $startedAt,
        ]);
        $startedAttrs = Deployments::canceledAttributes($started);
        $this->assertGreaterThanOrEqual(14, $startedAttrs['buildDuration']);

        $negative = Deployments::canceledAttributes($unstarted, -5);
        $this->assertSame(0, $negative['buildDuration']);

        $limit = \defined('APP_LOG_LENGTH_LIMIT') ? APP_LOG_LENGTH_LIMIT : 1000000;
        $oversized = new Document([
            '$id' => 'oversized',
            'buildLogs' => \str_repeat('a', $limit),
        ]);
        $truncatedAttrs = Deployments::canceledAttributes($oversized, 3);
        $this->assertSame(3, $truncatedAttrs['buildDuration']);
        $this->assertSame($limit, \strlen($truncatedAttrs['buildLogs']));
        $this->assertStringEndsWith("Build has been canceled. \033[0m\n", $truncatedAttrs['buildLogs']);
    }

    public function testCancelSupersededCancelsOnlyOlderInFlightVcsBuildsOnSameResourceAndBranch(): void
    {
        $site = new Document(['$id' => 'site1', '$sequence' => '5', '$collection' => 'sites']);
        $project = new Document(['$id' => 'proj1', 'region' => 'default']);

        $store = [
            'd1' => new Document([
                '$id' => 'd1',
                '$sequence' => 1,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'providerCommitHash' => 'commit1',
                'status' => 'waiting',
            ]),
            'd2' => new Document([
                '$id' => 'd2',
                '$sequence' => 2,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'providerCommitHash' => 'commit2',
                'status' => 'building',
                'buildLogs' => "Installing deps...\n",
            ]),
            'd3' => new Document([
                '$id' => 'd3',
                '$sequence' => 3,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'ready',
            ]),
            'd4' => new Document([
                '$id' => 'd4',
                '$sequence' => 4,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'canceled',
            ]),
            'd5' => new Document([
                '$id' => 'd5',
                '$sequence' => 5,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'feature',
                'status' => 'building',
            ]),
            'd6' => new Document([
                '$id' => 'd6',
                '$sequence' => 6,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'manual',
                'providerBranch' => 'main',
                'status' => 'waiting',
            ]),
            'd7' => new Document([
                '$id' => 'd7',
                '$sequence' => 7,
                'resourceId' => 'site2',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'building',
            ]),
            'd8' => new Document([
                '$id' => 'd8',
                '$sequence' => 11,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'providerCommitHash' => 'commit11',
                'status' => 'waiting',
            ]),
            'd9' => new Document([
                '$id' => 'd9',
                '$sequence' => 10,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'providerCommitHash' => 'commit10',
                'status' => 'waiting',
            ]),
        ];

        $database = $this->createInMemoryDatabase($store);
        $deletedJobPaths = [];
        $deployments = $this->createDeploymentsService($database, $project, $deletedJobPaths);

        $superseded = $deployments->cancelSuperseded($site, $store['d9']);
        $supersededIds = \array_map(static fn (Document $doc): string => $doc->getId(), $superseded);

        $this->assertSame(['d1', 'd2'], $supersededIds);
        $this->assertSame('canceled', $store['d1']->getAttribute('status'));
        $this->assertSame('canceled', $store['d2']->getAttribute('status'));
        $this->assertStringContainsString('Build has been canceled.', (string) $store['d2']->getAttribute('buildLogs'));

        $this->assertSame('ready', $store['d3']->getAttribute('status'));
        $this->assertSame('canceled', $store['d4']->getAttribute('status'));
        $this->assertSame('building', $store['d5']->getAttribute('status'));
        $this->assertSame('waiting', $store['d6']->getAttribute('status'));
        $this->assertSame('building', $store['d7']->getAttribute('status'));
        $this->assertSame('waiting', $store['d8']->getAttribute('status'));
        $this->assertSame('waiting', $store['d9']->getAttribute('status'));

        $this->assertSame([
            '/v1/jobs/' . Deployments::id('proj1', 'd1'),
            '/v1/jobs/' . Deployments::id('proj1', 'd2'),
        ], $deletedJobPaths);
    }

    public function testCancelSupersededSkipsNonVcsEmptyBranchAlreadyCanceledAndLostRaces(): void
    {
        $site = new Document(['$id' => 'site1', '$sequence' => '5', '$collection' => 'sites']);
        $project = new Document(['$id' => 'proj1', '$sequence' => '9', 'region' => 'default']);

        $findCalls = 0;
        $database = $this->createStub(Database::class);
        $database->method('find')->willReturnCallback(static function () use (&$findCalls): array {
            $findCalls++;
            return [
                new Document(['$id' => 'raced', '$sequence' => 1, 'status' => 'building']),
            ];
        });
        $database->method('getDocument')->willReturn(new Document(['$id' => 'raced', '$sequence' => 1, 'status' => 'building']));
        $database->method('updateDocuments')->willReturn(0);

        $deletedJobPaths = [];
        $deployments = $this->createDeploymentsService($database, $project, $deletedJobPaths);

        $this->assertSame([], $deployments->cancelSuperseded($site, new Document(['$id' => 'm1', 'type' => 'manual', 'providerBranch' => 'main', 'status' => 'waiting'])));
        $this->assertSame([], $deployments->cancelSuperseded($site, new Document(['$id' => 'v1', 'type' => 'vcs', 'providerBranch' => '', 'status' => 'waiting'])));
        $this->assertSame([], $deployments->cancelSuperseded($site, new Document(['$id' => 'v2', 'type' => 'vcs', 'providerBranch' => 'main', 'status' => 'canceled'])));
        $this->assertSame(0, $findCalls, 'Ineligible deployments must return without querying');

        $racedResult = $deployments->cancelSuperseded($site, new Document(['$id' => 'v3', '$sequence' => 2, 'type' => 'vcs', 'providerBranch' => 'main', 'status' => 'waiting']));
        $this->assertSame([], $racedResult, 'A deployment that finished before the guarded update must not be reported as canceled');
        $this->assertSame([], $deletedJobPaths);
    }

    public function testCancelSupersededAcquiresLockAndPreservesConcurrentLogAppends(): void
    {
        $site = new Document(['$id' => 'site1', '$sequence' => '5', '$collection' => 'sites']);
        $project = new Document(['$id' => 'proj1', '$sequence' => '9', 'region' => 'default']);

        $store = [
            'd1' => new Document([
                '$id' => 'd1',
                '$sequence' => 1,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'building',
                'buildLogs' => "Step 1\n",
            ]),
            'd2' => new Document([
                '$id' => 'd2',
                '$sequence' => 2,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'waiting',
            ]),
        ];

        $database = $this->createInMemoryDatabase($store);
        $acquiredKeys = [];
        $locks = static function (string $key, int $ttl, callable $callback, float $timeout = 0.0) use (&$acquiredKeys, &$store): mixed {
            $acquiredKeys[] = [$key, $ttl, $timeout];
            // Simulate a Jobs log append finishing before the lock was granted.
            $store['d1']->setAttribute('buildLogs', "Step 1\nStep 2 (concurrent append)\n");
            return $callback();
        };

        $deletedJobPaths = [];
        $deployments = $this->createDeploymentsService($database, $project, $deletedJobPaths, $locks);
        $superseded = $deployments->cancelSuperseded($site, $store['d2']);

        $this->assertCount(1, $superseded);
        $this->assertSame([[Deployments::lock('9', 'd1'), 30, 10.0]], $acquiredKeys);
        $this->assertStringContainsString('Step 2 (concurrent append)', (string) $store['d1']->getAttribute('buildLogs'));
        $this->assertStringContainsString('Build has been canceled.', (string) $store['d1']->getAttribute('buildLogs'));
    }

    public function testCancelSupersededFallsBackWhenLockContends(): void
    {
        $site = new Document(['$id' => 'site1', '$sequence' => '5', '$collection' => 'sites']);
        $project = new Document(['$id' => 'proj1', '$sequence' => '9', 'region' => 'default']);

        $store = [
            'd1' => new Document([
                '$id' => 'd1',
                '$sequence' => 1,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'building',
            ]),
            'd2' => new Document([
                '$id' => 'd2',
                '$sequence' => 2,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'waiting',
            ]),
        ];

        $database = $this->createInMemoryDatabase($store);
        $locks = static function (): mixed {
            throw new Contention('Lock timed out');
        };

        $deletedJobPaths = [];
        $deployments = $this->createDeploymentsService($database, $project, $deletedJobPaths, $locks);
        $superseded = $deployments->cancelSuperseded($site, $store['d2']);

        $this->assertCount(1, $superseded);
        $this->assertSame('canceled', $store['d1']->getAttribute('status'));
        $this->assertSame(['/v1/jobs/' . Deployments::id('proj1', 'd1')], $deletedJobPaths);
    }

    public function testCancelSupersededContinuesIfBackendJobCancellationThrows(): void
    {
        $site = new Document(['$id' => 'site1', '$sequence' => '5', '$collection' => 'sites']);
        $project = new Document(['$id' => 'proj1', 'region' => 'default']);

        $store = [
            'd1' => new Document([
                '$id' => 'd1',
                '$sequence' => 1,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'waiting',
            ]),
            'd2' => new Document([
                '$id' => 'd2',
                '$sequence' => 2,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'processing',
            ]),
            'd3' => new Document([
                '$id' => 'd3',
                '$sequence' => 3,
                'resourceId' => 'site1',
                'resourceType' => 'sites',
                'type' => 'vcs',
                'providerBranch' => 'main',
                'status' => 'waiting',
            ]),
        ];

        $database = $this->createInMemoryDatabase($store);
        $attempts = [];
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$attempts): ResponseInterface {
            $path = $request->getUri()->getPath();
            $attempts[] = $path;
            if (\count($attempts) === 1) {
                throw new \RuntimeException('Orchestrator unavailable');
            }
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(204);
            return $response;
        });

        $deployments = new Deployments(
            new Jobs($client),
            $database,
            $project,
            ['apiHostname' => 'localhost'],
            $this->createStub(BuildPublisher::class),
        );

        $superseded = $deployments->cancelSuperseded($site, $store['d3']);
        $this->assertSame(['d1', 'd2'], \array_map(static fn (Document $doc): string => $doc->getId(), $superseded));
        $this->assertSame('canceled', $store['d1']->getAttribute('status'));
        $this->assertSame('canceled', $store['d2']->getAttribute('status'));
        $this->assertCount(2, $attempts);
    }

    /**
     * @param array<int, string> $deletedJobPaths
     */
    private function createDeploymentsService(Database $database, Document $project, array &$deletedJobPaths, ?callable $locks = null): Deployments
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$deletedJobPaths): ResponseInterface {
            if ($request->getMethod() === 'DELETE') {
                $deletedJobPaths[] = $request->getUri()->getPath();
            }
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(204);
            return $response;
        });

        return new Deployments(
            new Jobs($client),
            $database,
            $project,
            ['apiHostname' => 'localhost'],
            $this->createStub(BuildPublisher::class),
            $locks,
        );
    }

    /**
     * @param array<string, Document> $store
     */
    private function createInMemoryDatabase(array &$store): Database
    {
        $database = $this->createStub(Database::class);

        $matches = static function (Document $doc, array $queries): bool {
            foreach ($queries as $query) {
                if (!$query instanceof Query) {
                    continue;
                }
                $attr = $query->getAttribute();
                $values = $query->getValues();
                $actual = $attr === '$id' ? $doc->getId() : ($attr === '$sequence' ? $doc->getSequence() : $doc->getAttribute($attr));
                $matched = match ($query->getMethod()) {
                    Method::Equal => \in_array($actual, $values, true),
                    Method::NotEqual => !\in_array($actual, $values, true),
                    Method::LessThan => $actual < $values[0],
                    default => true,
                };
                if (!$matched) {
                    return false;
                }
            }

            return true;
        };

        $database->method('find')->willReturnCallback(static function (string $collection, array $queries) use (&$store, $matches): array {
            $result = [];
            foreach ($store as $doc) {
                if ($matches($doc, $queries)) {
                    $result[] = clone $doc;
                }
            }

            return $result;
        });

        $database->method('getDocument')->willReturnCallback(static function (string $collection, string $id) use (&$store): Document {
            return isset($store[$id]) ? clone $store[$id] : new Document();
        });

        $database->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update, array $queries) use (&$store, $matches): int {
            $count = 0;
            foreach ($store as $doc) {
                if ($matches($doc, $queries)) {
                    $doc->setAttributes($update->getArrayCopy());
                    $count++;
                }
            }

            return $count;
        });

        return $database;
    }
}
