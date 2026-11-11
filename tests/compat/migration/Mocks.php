<?php

declare(strict_types=1);

namespace Tests\Compat\Migration;

use Override;
use Utopia\Migration\Resource;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Target;
use Utopia\Migration\Tests\Adapters\MockDestination;
use Utopia\Migration\Tests\Adapters\MockSource;

require_once \dirname(__DIR__, 3) . '/packages/migration/tests/Adapters/MockSource.php';
require_once \dirname(__DIR__, 3) . '/packages/migration/tests/Adapters/MockDestination.php';
require_once \dirname(__DIR__, 3) . '/packages/migration/tests/General/RequestTarget.php';

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
            // TransfersColumns::transferSchema(): columns before indexes, the
            // order a real source emits them in.
            'schema' => new class () extends MockSource {
                #[Override]
                protected function exportGroupDatabases(int $batchSize, array $resources): void
                {
                    foreach ([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN, Resource::TYPE_INDEX] as $type) {
                        if (Resource::isSupported($type, $resources)) {
                            parent::exportGroupDatabases($batchSize, [$type]);
                        }
                    }
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

/**
 * The Firebase source against the migration-api fixture: its calls to
 * `https://<host>/...` go to `<base>/<host>/...`, and it holds a token
 * already (Firebase asks Google for one through Target::call(), which no
 * override reaches; the firebase.jwt cases check the signed JWT apart).
 */
final class FirebaseFixture extends Firebase
{
    /**
     * @param array<string, mixed> $serviceAccount
     */
    public function __construct(array $serviceAccount, private readonly string $base)
    {
        parent::__construct($serviceAccount);
        (new \ReflectionProperty(Firebase::class, 'currentToken'))->setValue($this, 'test-token');
        (new \ReflectionProperty(Firebase::class, 'tokenExpires'))->setValue($this, PHP_INT_MAX);
        $this->headers['Authorization'] = 'Bearer test-token';
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $params
     * @param array<string, mixed> $responseHeaders
     * @return array<mixed>|string
     */
    #[Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], array &$responseHeaders = []): array|string
    {
        if (\str_starts_with($path, 'https://')) {
            $path = $this->base . '/' . \substr($path, 8);
        }

        return parent::call($method, $path, $headers, $params, $responseHeaders);
    }
}

/**
 * TargetTimeoutTest's ShortTimeoutTarget: a target whose timeouts are short
 * enough to wait out, and a public fetch() of Target::call().
 */
final class ShortTimeoutTarget extends Target
{
    public const int CONNECT_TIMEOUT = 1;

    public const int STALL_TIMEOUT = 3;

    public const int REQUEST_TIMEOUT = 1;

    #[Override]
    public static function getName(): string
    {
        return 'ShortTimeout';
    }

    #[Override]
    public static function getSupportedResources(): array
    {
        return [];
    }

    #[Override]
    public function run(array $resources, callable $callback, string $rootResourceId = ''): void
    {
    }

    #[Override]
    public function report(array $resources = [], array $resourceIds = []): array
    {
        return [];
    }

    /**
     * @return array<mixed>|string
     */
    public function fetch(string $url): array|string
    {
        return $this->call('GET', $url);
    }
}

/**
 * The resolvers of TargetRequestTest and AppwriteClientTest: `pin` connects
 * the URL's host and port to `address` (the fixture's), `refuse` throws.
 * Records every URL it receives.
 */
final class ResolverFixture
{
    /** @var list<string> */
    public array $urls = [];

    public function __construct(private readonly string $mode, private readonly string $address)
    {
    }

    /**
     * @return array<string>
     */
    public function __invoke(string $url): array
    {
        $this->urls[] = $url;
        if ($this->mode === 'refuse') {
            throw new \Exception('Refused');
        }

        return [\parse_url($url, PHP_URL_HOST) . ':' . \parse_url($url, PHP_URL_PORT) . ':' . $this->address];
    }
}
