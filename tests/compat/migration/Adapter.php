<?php

declare(strict_types=1);

namespace Tests\Compat\Migration;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\JsonDecoder\PassThruDecoder;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Migration\Cache;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationAppwrite;
use Utopia\Migration\Destinations\CSV as DestinationCSV;
use Utopia\Migration\Destinations\JSON as DestinationJSON;
use Utopia\Migration\Destinations\Local as DestinationLocal;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\ResourceSelector;
use Utopia\Migration\Resources\Database\Attribute;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider;
use Utopia\Migration\Source;
use Appwrite\Client as AppwriteClient;
use Appwrite\Query as AppwriteQuery;
use Appwrite\Services\TablesDB;
use Utopia\Migration\Sources\Appwrite\Reader\API as APIReader;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Migration\Sources\Appwrite\Reader\Database as DatabaseReader;
use Utopia\Migration\Sources\CSV as SourceCSV;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Sources\JSON as SourceJSON;
use Utopia\Migration\Target;
use Utopia\Migration\Transfer;
use Utopia\Migration\Warning;
use Utopia\Storage\Device\Local;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Query;

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
            // halaxa/json-machine as the JSON source reads files: the items of a
            // document written to this runtime's own file, and the error that
            // stopped the iteration.
            'json_machine.items' => function (array $a, Session $s) {
                $path = '/tmp/compat-fs/' . $s->ns . '/json-machine.json';
                @\mkdir(\dirname($path), 0755, true);
                \file_put_contents($path, $a['json']);
                $decoder = ($a['decoder'] ?? 'ext') === 'passthru' ? new PassThruDecoder() : new ExtJsonDecoder(true);
                $out = [];
                try {
                    foreach (Items::fromFile($path, ['decoder' => $decoder]) as $key => $value) {
                        $out[] = [$key, $value];
                    }
                    $error = null;
                } catch (\Throwable $e) {
                    $error = ['class' => $e::class, 'message' => $e->getMessage()];
                } finally {
                    @\unlink($path);
                }

                return ['items' => $out, 'error' => $error];
            },
            'source.props' => fn (array $a, Session $s) => Values::props($s->get($a['source']), ['resourceId', 'resourceChildId']),

            // Project databases on the Memory adapter, authorization off.
            'db.new' => function (array $a, Session $s) {
                $adapter = new MemoryAdapter();
                $adapter->setSupportForAttributes(!($a['schemaless'] ?? false));
                $db = new UtopiaDatabase($adapter, new UtopiaCache(new MemoryCache()));
                $db->setDatabase('appwrite')->setNamespace('_project');
                $db->getAuthorization()->disable();
                $db->create();

                return $s->handle($db);
            },
            'db.collection' => fn (array $a, Session $s) => !$s->get($a['db'])->createCollection(
                $a['id'],
                \array_map(static fn (array $d) => new UtopiaDocument($d), $a['attributes'] ?? []),
                \array_map(static fn (array $d) => new UtopiaDocument($d), $a['indexes'] ?? []),
            )->isEmpty(),
            'db.document' => fn (array $a, Session $s) => $s->get($a['db'])->createDocument($a['collection'], new UtopiaDocument($a['document']))->getSequence(),
            'db.get' => fn (array $a, Session $s) => $s->get($a['db'])->getDocument($a['collection'], $a['id'])->getArrayCopy(),
            'db.subquery_filters' => function (array $a, Session $s) {
                // Appwrite's virtual attributes of a database's collection of
                // tables: its columns and indexes.
                foreach (['subQueryAttributes' => 'attributes', 'subQueryIndexes' => 'indexes'] as $filter => $collection) {
                    UtopiaDatabase::addFilter(
                        $filter,
                        static fn (mixed $value) => null,
                        static fn (mixed $value, UtopiaDocument $document, UtopiaDatabase $database): array => $database->getAuthorization()->skip(
                            static fn (): array => $database->find($collection, [
                                Query::equal('collectionInternalId', [$document->getSequence()]),
                                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                            ]),
                        ),
                    );
                }

                return true;
            },
            // The migration-api fixture: a project's canned answers, and the
            // requests the library sent it.
            'api.routes' => fn (array $a, Session $s) => self::fixture($s, 'POST', '/__fixture/routes', ['project' => $a['project'], 'routes' => $a['routes']]),
            'api.requests' => fn (array $a, Session $s) => self::fixture($s, 'GET', '/__fixture/requests?project=' . \rawurlencode($a['project'])),
            'appwrite_source.new' => function (array $a, Session $s) {
                $db = isset($a['db']) ? $s->get($a['db']) : null;
                $type = $a['source'] ?? SourceAppwrite::SOURCE_API;
                $queries = \array_map(static fn (array $q) => match (\array_key_first($q)) {
                    'equal' => $type === SourceAppwrite::SOURCE_API ? AppwriteQuery::equal($q['equal'][0], $q['equal'][1]) : Query::equal($q['equal'][0], $q['equal'][1]),
                    'limit' => $type === SourceAppwrite::SOURCE_API ? AppwriteQuery::limit($q['limit']) : Query::limit($q['limit']),
                    default => throw new Fault('unknown query'),
                }, $a['queries'] ?? []);

                return $s->handle(new SourceAppwrite(
                    $a['project'] ?? 'source-project',
                    ($a['api'] ?? false) ? $s->service('migration-api') . '/v1' : ($a['endpoint'] ?? 'http://example.test/v1'),
                    $a['key'] ?? 'test-key',
                    static fn (?UtopiaDocument $document = null): ?UtopiaDatabase => $db,
                    $type,
                    $db,
                    $queries,
                ));
            },
            'appwrite_source.type' => fn (array $a, Session $s) => $s->get($a['source'])->getSourceType(),
            'appwrite.get_column' => fn (array $a, Session $s) => $s->handle(($a['attribute'] ?? false)
                ? SourceAppwrite::getAttribute(Values::resource($a['table'], $s), $a['column'])
                : SourceAppwrite::getColumn(Values::resource($a['table'], $s), $a['column'])),
            'appwrite.get_resource' => fn (array $a, Session $s) => $s->handle(match ($a['kind']) {
                'database' => SourceAppwrite::getDatabase($a['type'], $a['array']),
                'entity' => SourceAppwrite::getEntity($a['type'], $a['array']),
                'record' => SourceAppwrite::getRecord($a['type'], $a['array']),
                default => throw new Fault('unknown kind'),
            }),
            'firebase_source.new' => fn (array $a, Session $s) => $s->handle(new FirebaseFixture(
                $a['service_account'] ?? self::serviceAccount(),
                $s->service('migration-api') . '/p/' . $a['project'],
            )),
            'firebase.jwt' => function (array $a, Session $s) {
                $account = self::serviceAccount();
                $source = new \Utopia\Migration\Sources\Firebase($account);
                $jwt = (new \ReflectionMethod($source, 'calculateJWT'))->invoke($source);
                [$header, $claims, $signature] = \explode('.', $jwt);
                $decode = static fn (string $part): string => (string) \base64_decode(\strtr($part, '-_', '+/'), true);
                $public = \openssl_pkey_get_details(\openssl_pkey_get_private($account['private_key']))['key'];
                $claims = \json_decode($decode($claims), true);

                return [
                    'header' => \json_decode($decode($header), true),
                    'iss' => $claims['iss'],
                    'scope' => $claims['scope'],
                    'aud' => $claims['aud'],
                    'lifetime' => $claims['exp'] - $claims['iat'],
                    'verified' => \openssl_verify($header . '.' . \explode('.', $jwt)[1], $decode($signature), $public, 'sha256') === 1,
                ];
            },
            'firebase.sanitize_bucket_id' => fn (array $a, Session $s) => (new \ReflectionMethod(\Utopia\Migration\Sources\Firebase::class, 'sanitizeBucketId'))
                ->invoke((new \ReflectionClass(\Utopia\Migration\Sources\Firebase::class))->newInstanceWithoutConstructor(), $a['id']),
            'nhost_source.new' => function (array $a, Session $s) {
                [$host, $port] = \explode(':', $s->service('migration-nhost-db'));
                $source = new NHost('xxxxxxxxxxxx', 'eu-central-1', 'hasuraSecret', 'postgres', 'postgres', 'password');
                $source->pdo = new \PDO("pgsql:host={$host};port={$port};dbname=postgres", 'postgres', 'postgres');
                $source->storageURL = $s->service('migration-api') . '/p/' . $a['project'];

                return $s->handle($source);
            },
            'supabase_source.new' => function (array $a, Session $s) {
                [$host, $port] = \explode(':', $s->service('migration-supabase-db'));

                return $s->handle(new Supabase(
                    $s->service('migration-api') . '/p/' . $a['project'],
                    'test-key',
                    $a['host'] ?? $host,
                    'postgres',
                    'postgres',
                    'postgres',
                    $a['port'] ?? $port,
                ));
            },
            'nhost.status_code' => fn (array $a, Session $s) => (new \ReflectionMethod(NHost::class, 'statusCodeFromSqlState'))->invoke(null, $a['sqlstate']),
            'appwrite_destination.new' => function (array $a, Session $s) {
                $db = $s->get($a['db']);
                $platform = isset($a['platform']) ? $s->get($a['platform']) : $db;
                $dsn = $a['dsn'] ?? null;

                return $s->handle(new DestinationAppwrite(
                    project: $a['project'] ?? 'destination-project',
                    endpoint: ($a['api'] ?? false) ? $s->service('migration-api') . '/v1' : ($a['endpoint'] ?? 'http://example.test/v1'),
                    key: $a['key'] ?? 'test-key',
                    dbForProject: $db,
                    getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $db,
                    collectionStructure: $a['structure'] ?? ['attributes' => [], 'indexes' => []],
                    dbForPlatform: $platform,
                    projectInternalId: $a['internal_id'] ?? '1',
                    onDuplicate: OnDuplicate::from($a['on_duplicate'] ?? 'fail'),
                    getDatabaseDSN: $dsn === null ? null : static fn (DatabaseResource $resource): string => $dsn . ':' . $resource->getId(),
                    collectionStructures: $a['structures'] ?? [],
                ));
            },
            'appwrite_destination.dsn' => function (array $a, Session $s) {
                $method = new \ReflectionMethod(DestinationAppwrite::class, 'resolveDestinationDsn');

                return $method->invoke($s->get($a['destination']), Values::resource($a['resource'], $s));
            },
            'reader.api' => fn (array $a, Session $s) => $s->handle(new APIReader(new TablesDB(
                (new AppwriteClient())
                    ->setEndpoint($s->service('migration-api') . '/v1')
                    ->setProject($a['project'])
                    ->setKey($a['key'] ?? 'test-key'),
            ))),
            'reader.database' => fn (array $a, Session $s) => $s->handle(new DatabaseReader($s->get($a['db']))),
            'reader.call' => function (array $a, Session $s) {
                $reader = $s->get($a['reader']);
                $queries = \array_map(static fn (array $q) => match (\array_key_first($q)) {
                    'equal' => $reader->queryEqual($q['equal'][0], $q['equal'][1]),
                    'limit' => $reader->queryLimit($q['limit']),
                    'select' => $reader->querySelect($q['select']),
                    'cursor_after' => $reader->queryCursorAfter(\is_array($q['cursor_after']) ? Values::resource($q['cursor_after'], $s) : $q['cursor_after']),
                    default => throw new Fault('unknown query'),
                }, $a['queries'] ?? []);
                $resource = isset($a['resource']) ? Values::resource($a['resource'], $s) : null;
                $result = match ($a['method']) {
                    'report' => (static function () use ($reader, $a) {
                        $report = [];
                        $reader->report($a['resources'], $report, $a['resource_ids'] ?? []);

                        return $report;
                    })(),
                    'listDatabases' => $reader->listDatabases($queries),
                    'listTables' => $reader->listTables($resource, $queries),
                    'listColumns' => $reader->listColumns($resource, $queries),
                    'listIndexes' => $reader->listIndexes($resource, $queries),
                    'listRows' => $reader->listRows($resource, $queries),
                    'getRow' => $reader->getRow($resource, $a['row_id'], $queries),
                    'getSupportForAttributes' => $reader->getSupportForAttributes(),
                    default => throw new Fault('unknown reader method'),
                };

                return \is_array($result) && \array_is_list($result)
                    ? \array_map(static fn ($d) => $d instanceof UtopiaDocument ? $d->getArrayCopy() : $d, $result)
                    : $result;
            },
            'db.find' => fn (array $a, Session $s) => \array_map(
                static fn (UtopiaDocument $d) => $d->getArrayCopy(),
                $s->get($a['db'])->find($a['collection'], [Query::limit($a['limit'] ?? 100)]),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceAccount(): array
    {
        return \json_decode((string) \file_get_contents(__DIR__ . '/fixtures/firebase/service-account.json'), true);
    }

    private static function fixture(Session $s, string $method, string $path, ?array $body = null): mixed
    {
        $context = \stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : \json_encode($body),
            'ignore_errors' => true,
        ]]);
        $response = \file_get_contents($s->service('migration-api') . $path, false, $context);
        if ($response === false) {
            throw new Fault('migration-api is not reachable (tests/compat/migration/services.sh up)');
        }

        return \json_decode($response, true);
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
