<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Hooks\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\BatchCreated;
use Utopia\Database\Event\Document\BatchDeleted;
use Utopia\Database\Event\Document\BatchUpserted;
use Utopia\Database\Event\Document\Created;
use Utopia\Database\Event\Document\Deleted;
use Utopia\Database\Event\Document\Updated;
use Utopia\Database\Event\Domain;

final class UsageTest extends TestCase
{
    public static function deploymentOwners(): \Iterator
    {
        yield 'function' => ['functions', 'function'];
        yield 'site' => ['sites', 'site'];
    }

    #[DataProvider('deploymentOwners')]
    public function testDeploymentCreateIsAttributedToItsOwner(string $resourceType, string $owner): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Created('deployments', $this->deployment($resourceType, '42')));

        $this->assertSame([
            [$resourceType . '.deployments', $owner, '42'],
            [$resourceType . '.deployments.storage', $owner, '42'],
        ], $this->attribution($context));
        $this->assertSame(1, $context->getMetrics()[0]['value']);
    }

    #[DataProvider('deploymentOwners')]
    public function testDeploymentDeleteIsAttributedToItsOwner(string $resourceType, string $owner): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Deleted('deployments', $this->deployment($resourceType, '42')));

        $this->assertSame([
            [$resourceType . '.deployments', $owner, '42'],
            [$resourceType . '.deployments.storage', $owner, '42'],
        ], $this->attribution($context));
        $this->assertSame(-1, $context->getMetrics()[0]['value']);
    }

    public function testDeploymentAttributionSurvivesTheShutdownFill(): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Created('deployments', $this->deployment('sites', '9')));
        $context->fillMissingResource('project', 'project1', '1');

        $this->assertSame([
            ['sites.deployments', 'site', '9'],
            ['sites.deployments.storage', 'site', '9'],
        ], $this->attribution($context));
    }

    public function testDeploymentAttributionLeavesTheRequestAttributionUntouched(): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Created('deployments', $this->deployment('functions', '42')));
        $context->addMetric(METRIC_NETWORK_REQUESTS, 1);
        $context->fillMissingResource('project', 'project1', '1');

        $requests = \array_values(\array_filter(
            $this->attribution($context),
            static fn (array $row): bool => $row[0] === METRIC_NETWORK_REQUESTS,
        ));
        $this->assertSame([[METRIC_NETWORK_REQUESTS, 'project', '1']], $requests);
    }

    public static function documentWrites(): \Iterator
    {
        $collection = 'database_3_collection_8';
        $document = new Document(['$id' => 'document1', '$collection' => $collection]);

        yield 'create' => [new Created($collection, $document)];
        yield 'delete' => [new Deleted($collection, $document)];
        yield 'bulk create' => [new BatchCreated($collection, 3)];
        yield 'bulk delete' => [new BatchDeleted($collection, 3)];
        yield 'bulk upsert' => [new BatchUpserted($collection, 2, 1)];
    }

    #[DataProvider('documentWrites')]
    public function testDocumentWritesEmitNoDocumentMetrics(Domain $event): void
    {
        $context = new Context();

        (new Usage($context))->handle($event);

        $this->assertSame([], $context->getMetrics());
    }

    public static function gaugeOwnedCollections(): \Iterator
    {
        yield 'teams' => ['teams'];
        yield 'users' => ['users'];
        yield 'databases' => ['databases'];
        yield 'collections' => ['database_3'];
        yield 'buckets' => ['buckets'];
        yield 'files' => ['bucket_5'];
        yield 'functions' => ['functions'];
        yield 'sites' => ['sites'];
    }

    #[DataProvider('gaugeOwnedCollections')]
    public function testGaugeOwnedResourceWritesEmitNoMetrics(string $collection): void
    {
        $context = new Context();
        $hook = new Usage($context);
        $document = new Document([
            '$id' => 'resource1',
            '$collection' => $collection,
            'sizeOriginal' => 512,
        ]);

        $hook->handle(new Created($collection, $document));
        $hook->handle(new Deleted($collection, $document));

        $this->assertSame([], $context->getMetrics());
    }

    public function testDeploymentWritesEmitOnlyTheirResourceTypeMetrics(): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Created('deployments', new Document([
            '$id' => 'deployment1',
            '$collection' => 'deployments',
            'resourceInternalId' => '42',
            'resourceType' => 'functions',
        ])));

        $this->assertSame(
            ['functions.deployments', 'functions.deployments.storage'],
            \array_column($context->getMetrics(), 'key'),
        );
    }

    public static function formerlyReducedCollections(): \Iterator
    {
        yield 'users' => ['users'];
        yield 'databases' => ['databases'];
        yield 'collections' => ['database_3'];
        yield 'buckets' => ['buckets'];
        yield 'functions' => ['functions'];
        yield 'sites' => ['sites'];
    }

    #[DataProvider('formerlyReducedCollections')]
    public function testDeletingAResourceAddsNothingToReduce(string $collection): void
    {
        $context = new Context();

        (new Usage($context))->handle(new Deleted($collection, new Document([
            '$id' => 'resource1',
            '$collection' => $collection,
            'prefs' => ['theme' => 'dark'],
        ])));

        $this->assertSame([], $context->getReduce());
    }

    public function testSessionWritesAreCounted(): void
    {
        $context = new Context();
        $hook = new Usage($context);
        $session = new Document(['$id' => 'session1', '$collection' => 'sessions']);

        $hook->handle(new Created('sessions', $session));
        $hook->handle(new Updated('sessions', $session));
        $hook->handle(new Deleted('sessions', $session));
        $hook->handle(new BatchDeleted('sessions', 3));
        $hook->handle(new BatchCreated('sessions', 4));
        $hook->handle(new BatchUpserted('sessions', 2, 5));

        $this->assertSame(
            [['sessions', 1], ['sessions', -1], ['sessions', -3], ['sessions', 4], ['sessions', 2]],
            \array_map(static fn (array $metric): array => [$metric['key'], $metric['value']], $context->getMetrics()),
        );
    }

    public function testABulkDeploymentWriteEmitsNoMetrics(): void
    {
        $context = new Context();
        $hook = new Usage($context);

        $hook->handle(new BatchCreated('deployments', 2));
        $hook->handle(new BatchDeleted('deployments', 2));

        $this->assertSame([], $context->getMetrics());
    }

    public static function handledEvents(): \Iterator
    {
        yield 'create' => [Event::DocumentCreate, true];
        yield 'delete' => [Event::DocumentDelete, true];
        yield 'bulk create' => [Event::DocumentsCreate, true];
        yield 'bulk delete' => [Event::DocumentsDelete, true];
        yield 'bulk upsert' => [Event::DocumentsUpsert, true];
        yield 'update' => [Event::DocumentUpdate, false];
        yield 'read' => [Event::DocumentRead, false];
        yield 'find' => [Event::DocumentFind, false];
    }

    #[DataProvider('handledEvents')]
    public function testItSelectsOnlyTheWritesItCounts(Event $event, bool $handled): void
    {
        $this->assertSame($handled, (new Usage(new Context()))->handles($event));
    }

    private function deployment(string $resourceType, string $resourceInternalId): Document
    {
        return new Document([
            '$id' => 'deployment1',
            '$collection' => 'deployments',
            'resourceId' => 'owner1',
            'resourceInternalId' => $resourceInternalId,
            'resourceType' => $resourceType,
            'sourceSize' => 2048,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function attribution(Context $context): array
    {
        return \array_map(
            static fn (array $metric): array => [$metric['key'], $metric['resourceType'], $metric['resourceInternalId']],
            $context->getMetrics(),
        );
    }
}
