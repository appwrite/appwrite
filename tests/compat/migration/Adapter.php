<?php

declare(strict_types=1);

namespace Tests\Compat\Migration;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Migration\Cache;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Destinations\JSON as DestinationJSON;
use Utopia\Migration\Destinations\Local as DestinationLocal;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\ResourceSelector;
use Utopia\Migration\Resources\Database\Attribute;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\CSV as SourceCSV;
use Utopia\Migration\Sources\JSON as SourceJSON;
use Utopia\Migration\Target;
use Utopia\Migration\Transfer;
use Utopia\Migration\Warning;
use Utopia\Storage\Device\Local;

require_once __DIR__ . '/Mocks.php';
require_once __DIR__ . '/Values.php';

/**
 * Maps tests/compat/migration/spec.json operations onto utopia-php/migration.
 * Glue only: decode arguments, call the library, encode the result
 * (Values.php). Test doubles of the PHP suite (MockSource, MockDestination
 * and the subclasses tests define inline) are in Mocks.php.
 */
final class Adapter implements Base
{
    private const RESOURCES = 'Utopia\\Migration\\Resources\\';

    public function operations(): array
    {
        return [
            // Resources.
            'resource.new' => fn (array $a, Session $s) => $s->handle(new (self::RESOURCES . $a['class'])(...Values::args($a['args'] ?? [], $s))),
            'resource.from_array' => function (array $a, Session $s) {
                $class = self::RESOURCES . $a['class'];
                $resource = isset($a['provider'])
                    ? OAuth2Provider::fromArray($a['provider'], Values::args($a['array'], $s))
                    : $class::fromArray(Values::args($a['array'], $s));

                return $resource === null ? null : $s->handle($resource);
            },
            'resource.describe' => fn (array $a, Session $s) => Values::describe(Values::resource($a['resource'], $s)),
            'resource.json' => fn (array $a, Session $s) => Values::resource($a['resource'], $s)->jsonSerialize(),
            'resource.json_encode' => fn (array $a, Session $s) => \json_encode(Values::resource($a['resource'], $s)),
            'resource.set' => function (array $a, Session $s) {
                $resource = Values::resource($a['resource'], $s);
                $result = $resource->{$a['method']}(...Values::args($a['args'] ?? [], $s));

                return $result === $resource ? ['$self' => true] : $result;
            },
            'resource.attribute' => fn (array $a, Session $s) => $s->handle(Values::resource($a['resource'], $s)->getAttribute()),
            'attribute.from_column' => fn (array $a, Session $s) => $s->handle(Attribute::fromColumn(Values::resource($a['column'], $s))),
            'resource.is_supported' => fn (array $a, Session $s) => Resource::isSupported($a['types'], $a['resources']),
            'column.resolve' => fn (array $a, Session $s) => Column::resolve($a['column']),
            'resource.equal' => fn (array $a, Session $s) => Values::resource($a['a'], $s) === Values::resource($a['b'], $s),

            // Exceptions and warnings.
            'exception.new' => fn (array $a, Session $s) => Values::exception(new MigrationException(
                $a['resourceName'],
                $a['resourceGroup'],
                $a['resourceId'] ?? null,
                $a['message'] ?? '',
                $a['code'] ?? 0,
                isset($a['previous']) ? new \Exception($a['previous']['message'], $a['previous']['code'] ?? 0) : null,
            )),
            'warning.new' => function (array $a, Session $s) {
                $w = new Warning($a['resourceName'], $a['resourceGroup'], $a['message'], ...(isset($a['resourceId']) ? [$a['resourceId']] : []));

                return Values::warning($w);
            },
            'selector.scope' => function (array $a, Session $s) {
                $selector = new ResourceSelector(...$a);

                return ['id' => $selector->getScopeId(), 'type' => $selector->getScopeType()];
            },
            'on_duplicate.resolve' => fn (array $a, Session $s) => OnDuplicate::from($a['mode'])->resolveSchemaAction(
                $a['exists'],
                ...\array_filter([
                    'sourceUpdatedAt' => $a['source'] ?? null,
                    'destUpdatedAt' => $a['dest'] ?? null,
                ], static fn ($v) => $v !== null),
            )->name,
            'on_duplicate.values' => fn (array $a, Session $s) => OnDuplicate::values(),

            // Cache.
            'cache.new' => fn (array $a, Session $s) => $s->handle(new Cache()),
            'cache.add' => fn (array $a, Session $s) => self::cache($a, $s)->add(Values::resource($a['resource'], $s)),
            'cache.add_all' => fn (array $a, Session $s) => self::cache($a, $s)->addAll(Values::resources($a['resources'], $s)),
            'cache.update' => fn (array $a, Session $s) => self::cache($a, $s)->update(Values::resource($a['resource'], $s)),
            'cache.update_all' => fn (array $a, Session $s) => self::cache($a, $s)->updateAll(Values::resources($a['resources'], $s)),
            'cache.remove' => fn (array $a, Session $s) => self::cache($a, $s)->remove(Values::resource($a['resource'], $s)),
            'cache.get' => fn (array $a, Session $s) => Values::entries(self::cache($a, $s)->get(
                isset($a['resource']) ? Values::resource($a['resource'], $s) : $a['name']
            )),
            'cache.get_all' => fn (array $a, Session $s) => \array_map(Values::entries(...), self::cache($a, $s)->getAll()),
            'cache.wipe' => fn (array $a, Session $s) => self::cache($a, $s)->wipe(),
            'cache.resolve_key' => fn (array $a, Session $s) => self::cache($a, $s)->resolveResourceCacheKey(Values::resource($a['resource'], $s)),

            // Transfer.
            'transfer.new' => fn (array $a, Session $s) => $s->handle(new Transfer($s->get($a['source']), $s->get($a['destination']))),
            'transfer.run' => function (array $a, Session $s) {
                $calls = [];
                $s->get($a['transfer'])->run(
                    $a['resources'],
                    Values::callback($a['callback'] ?? null, $calls),
                    $a['root_id'] ?? null,
                    $a['root_type'] ?? null,
                );

                return $calls;
            },
            'transfer.run_selector' => function (array $a, Session $s) {
                $calls = [];
                $s->get($a['transfer'])->runWithResourceSelector(
                    $a['resources'],
                    Values::callback($a['callback'] ?? null, $calls),
                    ...$a['selector'],
                );

                return $calls;
            },
            'transfer.status_counters' => fn (array $a, Session $s) => $s->get($a['transfer'])->getStatusCounters(),
            'transfer.report' => fn (array $a, Session $s) => $s->get($a['transfer'])->getReport(...(isset($a['status']) ? [$a['status']] : [])),
            'transfer.cache' => fn (array $a, Session $s) => $s->handle($s->get($a['transfer'])->getCache()),
            'transfer.current_resource' => fn (array $a, Session $s) => $s->get($a['transfer'])->getCurrentResource(),
            'transfer.extract_services' => fn (array $a, Session $s) => Transfer::extractServices($a['services']),

            // Targets: what sources and destinations share.
            'target.name' => fn (array $a, Session $s) => $s->get($a['target'])::getName(),
            'target.supported_resources' => fn (array $a, Session $s) => $s->get($a['target'])::getSupportedResources(),
            'target.report' => fn (array $a, Session $s) => $s->get($a['target'])->report(...self::reportArgs($a)),
            'target.errors' => fn (array $a, Session $s) => \array_map(Values::exception(...), $s->get($a['target'])->getErrors()),
            'target.warnings' => fn (array $a, Session $s) => \array_map(Values::warning(...), $s->get($a['target'])->getWarnings()),
            'target.add_error' => fn (array $a, Session $s) => $s->get($a['target'])->addError(new MigrationException(
                $a['resourceName'],
                $a['resourceGroup'],
                $a['resourceId'] ?? null,
                $a['message'] ?? '',
                $a['code'] ?? 0,
            )),
            'target.add_warning' => fn (array $a, Session $s) => $s->get($a['target'])->addWarning(
                new Warning($a['resourceName'], $a['resourceGroup'], $a['message'], $a['resourceId'] ?? '')
            ),
            'target.register_cache' => fn (array $a, Session $s) => self::registerCache($s->get($a['target']), $s->get($a['cache'])),
            'target.lifecycle' => function (array $a, Session $s) {
                $target = $s->get($a['target']);
                $target->{$a['hook']}();

                return null;
            },
            'source.batch_sizes' => function (array $a, Session $s) {
                $source = $s->get($a['source']);

                return [
                    'auth' => $source->getAuthBatchSize(),
                    'databases' => $source->getDatabasesBatchSize(),
                    'storage' => $source->getStorageBatchSize(),
                    'functions' => $source->getFunctionsBatchSize(),
                    'messaging' => $source->getMessagingBatchSize(),
                    'sites' => $source->getSitesBatchSize(),
                    'integrations' => $source->getIntegrationsBatchSize(),
                    'backups' => $source->getBackupsBatchSize(),
                    'projects' => $source->getProjectsBatchSize(),
                    'domains' => $source->getDomainsBatchSize(),
                ];
            },
            'source.supports_database_status' => fn (array $a, Session $s) => $s->get($a['source'])->supportsDatabaseStatus(),
            'source.run' => function (array $a, Session $s) {
                $calls = [];
                $s->get($a['source'])->run($a['resources'], Values::callback($a['callback'] ?? null, $calls), $a['root_id'] ?? '', $a['root_type'] ?? '');

                return $calls;
            },
            'source.export_resources' => function (array $a, Session $s) {
                $calls = [];
                $source = $s->get($a['source']);
                // exportResources() hands batches to the callback run() installs:
                // run() with the same resources installs it, then exports.
                $source->run($a['resources'], Values::callback($a['callback'] ?? null, $calls));

                return $calls;
            },
            'mock.source' => fn (array $a, Session $s) => $s->handle(Mocks::source($a['variant'] ?? 'plain')),
            'mock.push' => fn (array $a, Session $s) => $s->get($a['source'])->pushMockResource(Values::resource($a['resource'], $s)),
            'mock.source_state' => fn (array $a, Session $s) => Mocks::sourceState($s->get($a['source'])),
            'mock.destination' => fn (array $a, Session $s) => $s->handle(Mocks::destination($a['variant'] ?? 'plain')),
            'mock.destination_ids' => fn (array $a, Session $s) => $s->get($a['destination'])->getResourceTypeData($a['group'], $a['type']),
            'mock.destination_get' => function (array $a, Session $s) {
                $resource = $s->get($a['destination'])->getResourceById($a['group'], $a['type'], $a['id']);

                return $resource === null ? null : $s->handle($resource);
            },
            'mock.destination_state' => fn (array $a, Session $s) => Mocks::destinationState($s->get($a['destination'])),
            // Files the cases read and write (each runtime its own copy under /tmp/compat-fs/${ns}).
            'fs.put' => function (array $a, Session $s) {
                @\mkdir(\dirname($a['path']), 0755, true);

                return \file_put_contents($a['path'], $a['contents']) !== false;
            },
            'fs.put_fixture' => function (array $a, Session $s) {
                @\mkdir(\dirname($a['path']), 0755, true);

                return \copy(__DIR__ . '/fixtures/' . $a['fixture'], $a['path']);
            },
            'fs.get' => fn (array $a, Session $s) => \is_file($a['path']) ? \file_get_contents($a['path']) : null,
            'fs.exists' => fn (array $a, Session $s) => \file_exists($a['path']),

            // Sources and destinations of files.
            'json_source.new' => fn (array $a, Session $s) => $s->handle(isset($a['child'])
                ? SourceJSON::fromResourceIds($a['resource_id'], $a['child'], $a['file_path'], new Local($a['root']), null)
                : new SourceJSON($a['resource_id'], $a['file_path'], new Local($a['root']), null)),
            'csv_source.new' => fn (array $a, Session $s) => $s->handle(isset($a['child'])
                ? SourceCSV::fromResourceIds($a['resource_id'], $a['child'], $a['file_path'], new Local($a['root']), isset($a['db']) ? $s->get($a['db']) : null)
                : new SourceCSV($a['resource_id'], $a['file_path'], new Local($a['root']), isset($a['db']) ? $s->get($a['db']) : null)),
            'csv.detect_delimiter' => function (array $a, Session $s) {
                $stream = \fopen(__DIR__ . '/fixtures/' . $a['fixture'], 'r');
                try {
                    $method = new \ReflectionMethod(SourceCSV::class, 'delimiter');

                    return $method->invoke((new \ReflectionClass(SourceCSV::class))->newInstanceWithoutConstructor(), $stream);
                } finally {
                    \fclose($stream);
                }
            },
            'json_destination.new' => fn (array $a, Session $s) => $s->handle(isset($a['child'])
                ? DestinationJSON::fromResourceIds(new Local($a['root']), $a['resource_id'], $a['child'], $a['directory'], $a['filename'], $a['allowed'] ?? [])
                : new DestinationJSON(new Local($a['root']), $a['resource_id'], $a['directory'], $a['filename'], $a['allowed'] ?? [])),
            'csv_destination.new' => fn (array $a, Session $s) => $s->handle(isset($a['child'])
                ? DestinationCSV::fromResourceIds(new Local($a['root']), $a['resource_id'], $a['child'], $a['directory'], $a['filename'], $a['allowed'] ?? [], ...($a['options'] ?? []))
                : new DestinationCSV(new Local($a['root']), $a['resource_id'], $a['directory'], $a['filename'], $a['allowed'] ?? [], ...($a['options'] ?? []))),
            'local_destination.new' => fn (array $a, Session $s) => $s->handle(new DestinationLocal($a['path'])),
            'destination.import' => function (array $a, Session $s) {
                $calls = [];
                $destination = $s->get($a['destination']);
                $import = (fn (array $resources, callable $callback) => $this->import($resources, $callback))->bindTo($destination, $destination);
                $import(Values::resources($a['resources'], $s), Values::callback($a['callback'] ?? null, $calls));

                return $calls;
            },
            'export.props' => fn (array $a, Session $s) => Values::props($s->get($a['target']), ['resourceId', 'resourceChildId', 'outputFile']),
            'export.local_file' => function (array $a, Session $s) {
                $d = $s->get($a['destination']);
                $root = Values::prop($d, 'local')->getRoot();
                $file = $root . '/' . Values::prop($d, 'outputFile') . '.' . ($d instanceof DestinationCSV ? 'csv' : 'json');

                return \is_file($file) ? \file_get_contents($file) : null;
            },
            'export.discard_local' => function (array $a, Session $s) {
                $root = Values::prop($s->get($a['destination']), 'local')->getRoot();
                \exec('rm -rf ' . \escapeshellarg($root));

                return !\file_exists($root);
            },
            'source.props' => fn (array $a, Session $s) => Values::props($s->get($a['source']), ['resourceId', 'resourceChildId']),
        ];
    }

    private static function cache(array $a, Session $s): Cache
    {
        return $s->get($a['cache']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    private static function reportArgs(array $a): array
    {
        return [$a['resources'] ?? [], $a['resource_ids'] ?? []];
    }

    private static function registerCache(Target $target, Cache $cache): null
    {
        $target->registerCache($cache);

        return null;
    }
}
