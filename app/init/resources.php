<?php

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Utopia\Database\Documents\User;
use Executor\Executor;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Lock\Adapter\Redis as LockRedisAdapter;
use Utopia\Lock\Exception\LockAcquireException;
use Utopia\Lock\Lock;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;

// Runtime Execution
global $register;
global $container;
$container = new Container();

$container->set('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

$container->set('hooks', function ($register) {
    return $register->get('hooks');
}, ['register']);

$container->set('register', fn () => $register);

$container->set('localeCodes', function () {
    return array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', []));
});

// Queues - shared infrastructure (stateless pool wrappers)
$container->set('publisher', function (Group $pools) {
    return new BrokerPool(publisher: $pools->get('publisher'));
}, ['pools']);
$container->set('publisherDatabases', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherFunctions', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMigrations', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMails', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherDeletes', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMessaging', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherWebhooks', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherForAudits', fn (Publisher $publisher) => new AuditPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_AUDITS_QUEUE_NAME', Event::AUDITS_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForCertificates', fn (Publisher $publisher) => new CertificatePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForScreenshots', fn (Publisher $publisher) => new ScreenshotPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForUsage', fn (Publisher $publisher) => new UsagePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForExecutions', fn (Publisher $publisher) => new ExecutionPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_EXECUTIONS_QUEUE_NAME', Event::EXECUTIONS_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForMigrations', fn (Publisher $publisher) => new MigrationPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForStatsResources', fn (Publisher $publisher) => new StatsResourcesPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME))
), ['publisher']);

/**
 * Platform configuration
 */
$container->set('platform', function () {
    return Config::getParam('platform', []);
}, []);

$container->set('console', function () {
    return new Document(Config::getParam('console'));
}, []);

$container->set('authorization', function () {
    return new Authorization();
}, []);

$container->set('dbForPlatform', function (Group $pools, Cache $cache, Authorization $authorization) {

    $adapter = new DatabasePool($pools->get('console'));
    $database = new Database($adapter, $cache);

    $database
        ->setDatabase(APP_DATABASE)
        ->setAuthorization($authorization)
        ->setNamespace('_console')
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', 'console')
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

    $database->setDocumentType('users', User::class);

    return $database;
}, ['pools', 'cache', 'authorization']);

$container->set('getLogsDB', function (Group $pools, Cache $cache, Authorization $authorization) {
    $database = null;

    return function (?Document $project = null) use ($pools, $cache, $authorization, &$database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
            return $database;
        }

        $adapter = new DatabasePool($pools->get('logs'));
        $database = new Database($adapter, $cache);

        $database
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
        }

        return $database;
    };
}, ['pools', 'cache', 'authorization']);

$container->set('telemetry', fn () => new NoTelemetry());

$container->set('cache', function (Group $pools, Telemetry $telemetry) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = new CachePool($pools->get($value));
    }

    $cache = new Cache(new Sharding($adapters));
    $cache->setTelemetry($telemetry);

    return $cache;
}, ['pools', 'telemetry']);

$container->set('redis', function () {
    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int) $port);
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

$container->set('timelimit', function (\Redis $redis) {
    return function (string $key, int $limit, int $time) use ($redis) {
        return new TimeLimitRedis($key, $limit, $time, $redis);
    };
}, ['redis']);

// Extract the collection segment ("keys" / "projects" / "users") from keys
// of the form "lock:platform:{target}:{id}" so metrics can slice by target.
// Used by both distributedLock and distributedLockOrFail factories below.
$lockTargetOf = function (string $key): string {
    $parts = explode(':', $key, 4);
    return $parts[2] ?? 'unknown';
};

/**
 * Distributed-lock factory: skip-on-contention variant.
 *
 * For idempotent writes where losing the race is correct (e.g., per-request
 * `accessedAt` updates from N pods all writing the same value). On contention,
 * the callback is silently skipped — another pod is doing the same work.
 *
 * Behavior:
 *  - Non-blocking acquire (one attempt). On conflict, skip and return.
 *  - Fail-open: if Redis is unreachable, run the callback unlocked + warn.
 *  - Kill switch: `_APP_LOCKING_ENABLED=disabled` runs the callback unlocked.
 *
 * Returns void — the caller can't distinguish "acquired and ran" from "skipped".
 *
 * Metric: `lock.attempts{outcome,target}` where outcome ∈ {acquired, skipped,
 * backend_error, release_error}.
 */
$container->set('distributedLock', function (\Redis $redis, Telemetry $telemetry) use ($lockTargetOf) {
    $enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
    $attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');

    if (! $enabled) {
        return function (string $key, \Closure $fn, float $ttl = 5.0): void {
            $fn();
        };
    }

    return function (string $key, \Closure $fn, float $ttl = 5.0) use ($redis, $attempts, $lockTargetOf): void {
        $target = $lockTargetOf($key);
        $lock = new Lock(new LockRedisAdapter($redis), $key, $ttl);

        try {
            $acquired = $lock->acquire();
        } catch (LockAcquireException $e) {
            $attempts->add(1, ['outcome' => 'backend_error', 'target' => $target]);
            Console::warning("Lock backend unavailable for {$key}, proceeding unlocked: {$e->getMessage()}");
            $fn();
            return;
        }

        if (! $acquired) {
            $attempts->add(1, ['outcome' => 'skipped', 'target' => $target]);
            return;
        }

        $attempts->add(1, ['outcome' => 'acquired', 'target' => $target]);
        try {
            $fn();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $attempts->add(1, ['outcome' => 'release_error', 'target' => $target]);
                Console::warning("Lock release failed for {$key}: {$e->getMessage()}");
            }
        }
    };
}, ['redis', 'telemetry']);

/**
 * Distributed-lock factory: 409-on-contention variant.
 *
 * For explicit user-write endpoints where read-modify-write on shared mutable
 * state must NOT silently drop a request. On contention, throws
 * `Exception::GENERAL_RESOURCE_LOCKED` (HTTP 409) so the client retries.
 *
 * Behavior:
 *  - Blocking acquire with short timeout (default 3s).
 *  - On timeout, throws `GENERAL_RESOURCE_LOCKED`.
 *  - Fail-open: backend unreachable runs the callback unlocked + warning.
 *  - Kill switch: `_APP_LOCKING_ENABLED=disabled` runs the callback unlocked.
 *  - Returns the callback's return value so callers can use the result.
 *
 * Metric: `lock.attempts{outcome,target}` where outcome ∈ {acquired, contended,
 * backend_error, release_error}.
 */
$container->set('distributedLockOrFail', function (\Redis $redis, Telemetry $telemetry) use ($lockTargetOf) {
    $enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
    $attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');

    if (! $enabled) {
        return function (string $key, \Closure $fn, float $ttl = 10.0, float $waitTimeout = 3.0): mixed {
            return $fn();
        };
    }

    return function (string $key, \Closure $fn, float $ttl = 10.0, float $waitTimeout = 3.0) use ($redis, $attempts, $lockTargetOf): mixed {
        $target = $lockTargetOf($key);
        $lock = new Lock(new LockRedisAdapter($redis), $key, $ttl);

        try {
            $acquired = $lock->acquire(blocking: true, waitTimeout: $waitTimeout, retryDelay: 0.1);
        } catch (LockAcquireException $e) {
            $attempts->add(1, ['outcome' => 'backend_error', 'target' => $target]);
            Console::warning("Lock backend unavailable for {$key}, proceeding unlocked: {$e->getMessage()}");
            return $fn();
        }

        if (! $acquired) {
            $attempts->add(1, ['outcome' => 'contended', 'target' => $target]);
            throw new AppwriteException(
                AppwriteException::GENERAL_RESOURCE_LOCKED,
                "Resource '{$key}' is currently being modified by another request. Please retry."
            );
        }

        $attempts->add(1, ['outcome' => 'acquired', 'target' => $target]);
        try {
            return $fn();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $attempts->add(1, ['outcome' => 'release_error', 'target' => $target]);
                Console::warning("Lock release failed for {$key}: {$e->getMessage()}");
            }
        }
    };
}, ['redis', 'telemetry']);

$container->set('deviceForLocal', function (Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, new Local());
}, ['telemetry']);
function getDevice(string $root, string $connection = ''): Device
{
    $connection = ! empty($connection) ? $connection : System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (! empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';
        $url = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Throwable $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                if (! empty($url)) {
                    $bucketRoot = (! empty($bucket) ? $bucket . '/' : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $accessKey, $accessSecret, $url, $region, $acl);
                } else {
                    return new AWS($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                }
                // no break
            case STORAGE::DEVICE_DO_SPACES:
                $device = new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);

                return $device;
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(System::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL))) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = System::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = System::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                $s3EndpointUrl = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
                if (! empty($s3EndpointUrl)) {
                    $bucketRoot = (! empty($s3Bucket) ? $s3Bucket . '/' : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $s3AccessKey, $s3SecretKey, $s3EndpointUrl, $s3Region, $s3Acl);
                } else {
                    return new AWS($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
                }
                // no break
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                $device = new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);

                return $device;
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = System::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = System::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = System::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';

                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = System::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = System::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = System::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';

                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = System::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = System::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = System::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';

                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}

$container->set('geodb', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('geodb');
}, ['register']);

$container->set('passwordsDictionary', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('passwordsDictionary');
}, ['register']);

$container->set('servers', function () {
    $platforms = Config::getParam('sdks');
    $server = $platforms[APP_SDK_PLATFORM_SERVER];

    $languages = array_map(function ($language) {
        return strtolower($language['name']);
    }, $server['sdks']);

    return $languages;
});

$container->set('promiseAdapter', function ($register) {
    return $register->get('promiseAdapter');
}, ['register']);

$container->set('gitHub', function (Cache $cache) {
    return new VcsGitHub($cache);
}, ['cache']);

$container->set('plan', function () {
    return [];
});

$container->set('smsRates', function () {
    return [];
});

$container->set(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);

$container->set('executor', fn () => new Executor());
