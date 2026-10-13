<?php

declare(strict_types=1);

namespace Tests\Compat\Migration;

use Override;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Source;
use Utopia\Migration\Target;
use Utopia\Tests\Unit\Adapters\MockDestination;
use Utopia\Tests\Unit\Adapters\MockSource;

require_once \dirname(__DIR__, 3) . '/vendor/utopia-php/migration/tests/Migration/Unit/Adapters/MockSource.php';
require_once \dirname(__DIR__, 3) . '/vendor/utopia-php/migration/tests/Migration/Unit/Adapters/MockDestination.php';

/**
 * The PHP suite's test doubles: its MockSource and MockDestination, and the
 * subclasses its tests declare inline (counting runs, an extra legacy
 * property, a source that reports database statuses).
 */
final class Mocks
{
    public static function source(string $variant): MockSource
    {
        return match ($variant) {
            'plain' => new MockSource(),
            // TransferTest::testLegacySourceSubclassChildSelectorPropertyRemainsCompatible.
            'legacy_child' => new class () extends MockSource {
                protected $rootResourceChildId = ['external'];
            },
            // TransferTest::testExplicitSelectorDispatchesLegacyOverridesExactlyOnce.
            'counting' => new class () extends MockSource {
                public int $runCount = 0;

                /** @var array<string, string>|null */
                public ?array $selector = null;

                #[Override]
                public function run(array $resources, callable $callback, string $rootResourceId = '', string $rootResourceType = ''): void
                {
                    $this->runCount++;
                    parent::run($resources, $callback, $rootResourceId, $rootResourceType);
                }

                #[Override]
                public function runWithResourceSelector(
                    array $resources,
                    callable $callback,
                    string $resourceId,
                    string $resourceInternalId,
                    string $resourceType,
                    string $parentResourceId,
                    string $parentResourceInternalId,
                    string $parentResourceType,
                ): void {
                    $this->selector = [
                        'resourceId' => $resourceId,
                        'resourceInternalId' => $resourceInternalId,
                        'resourceType' => $resourceType,
                        'parentResourceId' => $parentResourceId,
                        'parentResourceInternalId' => $parentResourceInternalId,
                        'parentResourceType' => $parentResourceType,
                    ];
                    parent::runWithResourceSelector(
                        $resources,
                        $callback,
                        $resourceId,
                        $resourceInternalId,
                        $resourceType,
                        $parentResourceId,
                        $parentResourceInternalId,
                        $parentResourceType,
                    );
                }
            },
            // AppwriteDatabaseStatusTest: a source reporting database statuses.
            'status' => new class () extends MockSource {
                #[Override]
                public function supportsDatabaseStatus(): bool
                {
                    return true;
                }
            },
            default => throw new \Tests\Compat\Fault("unknown mock source `{$variant}`"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourceState(MockSource $source): array
    {
        return [
            'runCount' => \property_exists($source, 'runCount') ? $source->runCount : null,
            'selector' => \property_exists($source, 'selector') ? $source->selector : null,
            'resources' => \array_map(
                static fn (array $types) => \array_map(static fn (array $byId) => \array_keys($byId), $types),
                $source->getMockResources(),
            ),
        ];
    }

    public static function destination(string $variant): MockDestination
    {
        return match ($variant) {
            'plain' => new MockDestination(),
            'counting' => new class () extends MockDestination {
                public int $runCount = 0;

                #[Override]
                public function run(array $resources, callable $callback, string $rootResourceId = '', string $rootResourceType = ''): void
                {
                    $this->runCount++;
                    parent::run($resources, $callback, $rootResourceId, $rootResourceType);
                }
            },
            default => throw new \Tests\Compat\Fault("unknown mock destination `{$variant}`"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function destinationState(MockDestination $destination): array
    {
        return [
            'runCount' => \property_exists($destination, 'runCount') ? $destination->runCount : null,
            'data' => \array_map(
                static fn (array $types) => \array_map(static fn (array $byId) => \array_keys($byId), $types),
                $destination->data,
            ),
        ];
    }

    /**
     * TargetTest: an external target implementing the legacy three-argument run().
     */
    public static function legacyTarget(): Target
    {
        return new class () extends Target {
            /** @var array{resources: array<string>, rootResourceId: string}|null */
            public ?array $invocation = null;

            #[Override]
            public static function getName(): string
            {
                return 'Legacy';
            }

            #[Override]
            public static function getSupportedResources(): array
            {
                return [];
            }

            #[Override]
            public function run(array $resources, callable $callback, string $rootResourceId = ''): void
            {
                $this->invocation = [
                    'resources' => $resources,
                    'rootResourceId' => $rootResourceId,
                ];
                $callback([]);
            }

            #[Override]
            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }
        };
    }
}
