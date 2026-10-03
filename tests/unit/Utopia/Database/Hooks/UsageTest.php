<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Hooks\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Event;

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

        (new Usage($context))->handle(Event::DocumentCreate, $this->deployment($resourceType, '42'));

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

        (new Usage($context))->handle(Event::DocumentDelete, $this->deployment($resourceType, '42'));

        $this->assertSame([
            [$resourceType . '.deployments', $owner, '42'],
            [$resourceType . '.deployments.storage', $owner, '42'],
        ], $this->attribution($context));
        $this->assertSame(-1, $context->getMetrics()[0]['value']);
    }

    public function testDeploymentAttributionSurvivesTheShutdownFill(): void
    {
        $context = new Context();

        (new Usage($context))->handle(Event::DocumentCreate, $this->deployment('sites', '9'));
        $context->fillMissingResource('project', 'project1', '1');

        $this->assertSame([
            ['sites.deployments', 'site', '9'],
            ['sites.deployments.storage', 'site', '9'],
        ], $this->attribution($context));
    }

    public function testDeploymentAttributionLeavesTheRequestAttributionUntouched(): void
    {
        $context = new Context();

        (new Usage($context))->handle(Event::DocumentCreate, $this->deployment('functions', '42'));
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
        yield 'create' => [Event::DocumentCreate, []];
        yield 'delete' => [Event::DocumentDelete, []];
        yield 'bulk create' => [Event::DocumentsCreate, ['modified' => 3]];
        yield 'bulk delete' => [Event::DocumentsDelete, ['modified' => 3]];
        yield 'bulk upsert' => [Event::DocumentsUpsert, ['created' => 2, 'updated' => 1]];
    }

    /**
     * @param array<string, int> $attributes
     */
    #[DataProvider('documentWrites')]
    public function testDocumentWritesEmitNoDocumentMetrics(Event $event, array $attributes): void
    {
        $context = new Context();

        (new Usage($context))->handle($event, new Document([
            '$id' => 'document1',
            '$collection' => 'database_3_collection_8',
            ...$attributes,
        ]));

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

        $hook->handle(Event::DocumentCreate, $document);
        $hook->handle(Event::DocumentDelete, $document);

        $this->assertSame([], $context->getMetrics());
    }

    public function testDeploymentWritesEmitOnlyTheirResourceTypeMetrics(): void
    {
        $context = new Context();

        (new Usage($context))->handle(Event::DocumentCreate, new Document([
            '$id' => 'deployment1',
            '$collection' => 'deployments',
            'resourceInternalId' => '42',
            'resourceType' => 'functions',
        ]));

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

        (new Usage($context))->handle(Event::DocumentDelete, new Document([
            '$id' => 'resource1',
            '$collection' => $collection,
            'prefs' => ['theme' => 'dark'],
        ]));

        $this->assertSame([], $context->getReduce());
    }

    public function testSessionWritesAreCounted(): void
    {
        $context = new Context();
        $hook = new Usage($context);
        $session = new Document(['$id' => 'session1', '$collection' => 'sessions']);

        $hook->handle(Event::DocumentCreate, $session);
        $hook->handle(Event::DocumentUpdate, $session);
        $hook->handle(Event::DocumentDelete, $session);
        $hook->handle(Event::DocumentsDelete, new Document(['$collection' => 'sessions', 'modified' => 3]));

        $this->assertSame(
            [['sessions', 1], ['sessions', -1], ['sessions', -3]],
            \array_map(static fn (array $metric): array => [$metric['key'], $metric['value']], $context->getMetrics()),
        );
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
