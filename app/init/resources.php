<?php

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
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
$container->set('publisherForUsage', fn (Publisher $publisher) => new UsagePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME))
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
        switch (strtolower(System::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
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

$container->set('plan', function (array $plan = []) {
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
