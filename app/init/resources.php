<?php

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Jobs as JobsPublisher;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Storage\Config\StorageCacheControl;
use Appwrite\Screenshots\Client as ScreenshotsClient;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Appwrite\Vcs\RepositoryWebhooks;
use Executor\Executor;
use OpenRuntimes\Orchestrator\Jobs;
use Psr\Http\Client\ClientInterface;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Pool as ClientPool;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Lock\Distributed;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool as ConnectionsPool;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Acl;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\RetryStrategy;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\DeviceType;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

global $register;
global $container;

$container = new Container();

$container->set('register', fn () => $register);

$container->set('logger', fn ($register) => $register->get('logger'), ['register']);

$container->set('hooks', fn ($register) => $register->get('hooks'), ['register']);

$container->set('console', fn () => new Document(Config::getParam('console')), []);

$container->set('platform', fn () => Config::getParam('platform', []), []);

$container->set('localeCodes', fn () => array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', [])));

$container->set('executor', fn () => new Executor(), []);

$container->set('jobs', function () {
    $client = (new Client(new CurlAdapter()))
        ->withBearerAuth(System::getEnv('_APP_JOBS_SECRET', ''))
        ->withTimeout(30);

    // No host on executor-only installs: keep the injection resolvable and
    // fail at call time instead (the client is only used when
    // _APP_BUILDS_BACKEND=orchestrator, which requires _APP_JOBS_HOST).
    $host = System::getEnv('_APP_JOBS_HOST', '');
    if ($host !== '') {
        $client = $client->withBaseUri($host);
    }

    return new Jobs($client);
}, []);

$container->set('screenshots', function () {
    $client = (new Client(new CurlAdapter()))
        ->withBaseUri(System::getEnv('_APP_BROWSER_HOST', 'http://appwrite-browser:3000/v1'))
        ->withTimeout((int) System::getEnv('_APP_SITES_TIMEOUT', 30));

    return new ScreenshotsClient($client);
}, []);

$container->set('telemetry', fn () => new NoTelemetry(), []);

$container->set('authorization', fn () => new Authorization(), []);

$container->set('publisher', fn (Group $pools) => new BrokerPool(publisher: $pools->get('publisher')), ['pools']);

$container->set('publisherDatabases', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherFunctions', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherMigrations', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherMails', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherDeletes', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherMessaging', fn (Publisher $publisher) => $publisher, ['publisher']);

$container->set('publisherWebhooks', fn (Publisher $publisher) => $publisher, ['publisher']);

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

$container->set('publisherForFunctions', fn (Publisher $publisher) => new FunctionPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
), ['publisher']);

$container->set('publisherForMigrations', fn (Publisher $publisher) => new MigrationPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForStatsResources', fn (Publisher $publisher) => new StatsResourcesPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForBuilds', fn (Publisher $publisher) => new BuildPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForJobs', fn (Publisher $publisher) => new JobsPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_JOBS_QUEUE_NAME', Event::JOBS_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForDatabase', fn (Publisher $publisherDatabases) => new DatabasePublisher(
    $publisherDatabases,
    new Queue(System::getEnv('_APP_DATABASE_QUEUE_NAME', Event::DATABASE_QUEUE_NAME))
), ['publisherDatabases']);

$container->set('publisherForDeletes', fn (Publisher $publisher) => new DeletePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForMails', fn (Publisher $publisher) => new MailPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_MAILS_QUEUE_NAME', Event::MAILS_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForMessaging', fn (Publisher $publisher) => new MessagingPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME))
), ['publisher']);

$container->set('publisherForNotifications', fn (Publisher $publisher) => new NotificationPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_NOTIFICATIONS_QUEUE_NAME', Event::NOTIFICATIONS_QUEUE_NAME))
), ['publisher']);

$container->set('databaseFactory', fn (Group $pools, Cache $cache, Authorization $authorization) => new DatabaseFactory(
    $pools,
    $cache,
    $authorization
), ['pools', 'cache', 'authorization']);

$container->set('dbForPlatform', fn (DatabaseFactory $databaseFactory) => $databaseFactory->platform(
    APP_DATABASE_TIMEOUT_MILLISECONDS_API,
    APP_DATABASE_QUERY_MAX_VALUES,
    ['host' => \gethostname(), 'project' => 'console']
), ['databaseFactory']);

$container->set('getLogsDB', function (DatabaseFactory $databaseFactory) {
    $database = null;

    return function (?Document $project = null) use ($databaseFactory, &$database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
            return $database;
        }

        $database = $databaseFactory->logs(
            $project,
            APP_DATABASE_TIMEOUT_MILLISECONDS_API,
            APP_DATABASE_QUERY_MAX_VALUES
        );

        return $database;
    };
}, ['databaseFactory']);

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

$container->set('cacheControlForStorage', fn () => fn (StorageCacheControl $config): string => \sprintf('private, max-age=%d', $config->maxAge));

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

$container->set('locks', fn (Group $pools) => fn (string $key, int $ttl, callable $callback, float $timeout = 0.0): mixed => $pools->get('lock')->use(
    fn (\Redis $redis) => (new Distributed($redis, $key, ttl: $ttl))->withLock($callback, timeout: $timeout)
), ['pools']);

$container->set('timelimit', fn (\Redis $redis) => fn (string $key, int $limit, int $time) => new TimeLimitRedis($key, $limit, $time, $redis), ['redis']);

$container->set('deviceForLocal', fn (Telemetry $telemetry) => new Device\Telemetry($telemetry, new Local()), ['telemetry']);

/**
 * Shared PSR-18 client for the S3-family devices: a lazy pool of keep-alive
 * connections. Concurrent coroutines borrow a connection exclusively for the
 * duration of each request, and connections persist between borrows so the
 * TCP/TLS handshake is paid per connection instead of per request.
 */
function getStorageClient(): ClientInterface
{
    static $client = null;

    return $client ??= new ClientPool(new ConnectionsPool(
        pool: new Stack(),
        name: 's3',
        size: 16,
        init: fn (): Client => new Client(new Retry(
            new CurlAdapter()->withConnectionReuse()->withTimeout(0.0),
            new RetryStrategy(),
        )),
    ));
}

function getDevice(string $root, string $connection = ''): Device
{
    $connection = ! empty($connection) ? $connection : System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (! empty($connection)) {
        $acl = Acl::Private;
        $device = DeviceType::Local->value;
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

        switch (DeviceType::tryFrom($device)) {
            case DeviceType::S3:
                if (! empty($url)) {
                    $bucketRoot = (! empty($bucket) ? "{$bucket}/" : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $accessKey, $accessSecret, $url, $region, $acl, client: getStorageClient());
                } else {
                    return new AWS($root, $accessKey, $accessSecret, $bucket, $region, $acl, client: getStorageClient());
                }
                // no break
            case DeviceType::DoSpaces:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl, client: getStorageClient());
            case DeviceType::Backblaze:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl, client: getStorageClient());
            case DeviceType::Linode:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl, client: getStorageClient());
            case DeviceType::Wasabi:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl, client: getStorageClient());
            case DeviceType::Local:
            default:
                return new Local($root);
        }
    } else {
        switch (DeviceType::tryFrom(strtolower(System::getEnv('_APP_STORAGE_DEVICE', DeviceType::Local->value)))) {
            case DeviceType::S3:
                $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = System::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = System::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = Acl::Private;
                $s3EndpointUrl = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
                if (! empty($s3EndpointUrl)) {
                    $bucketRoot = (! empty($s3Bucket) ? "{$s3Bucket}/" : '') . \ltrim($root, '/');

                    return new S3($bucketRoot, $s3AccessKey, $s3SecretKey, $s3EndpointUrl, $s3Region, $s3Acl, client: getStorageClient());
                } else {
                    return new AWS($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl, client: getStorageClient());
                }
                // no break
            case DeviceType::DoSpaces:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = Acl::Private;
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl, client: getStorageClient());
            case DeviceType::Backblaze:
                $backblazeAccessKey = System::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = System::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = System::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = System::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = Acl::Private;

                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl, client: getStorageClient());
            case DeviceType::Linode:
                $linodeAccessKey = System::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = System::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = System::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = System::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = Acl::Private;

                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl, client: getStorageClient());
            case DeviceType::Wasabi:
                $wasabiAccessKey = System::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = System::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = System::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = System::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = Acl::Private;

                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl, client: getStorageClient());
            case DeviceType::Local:
            default:
                return new Local($root);
        }
    }
}

$container->set('geodb', fn ($register) => $register->get('geodb'), ['register']);

$container->set('passwordsDictionary', fn ($register) => $register->get('passwordsDictionary'), ['register']);

$container->set('servers', function () {
    $platforms = Config::getParam('sdks');
    $server = $platforms[APP_SDK_PLATFORM_SERVER];

    $languages = array_map(fn ($language) => strtolower($language['name']), $server['sdks']);

    return $languages;
});

$container->set('promiseAdapter', fn ($register) => $register->get('promiseAdapter'), ['register']);

$container->set('vcsFactory', fn (Cache $cache) => new VcsFactory($cache), ['cache']);
$container->set('installationTokens', fn () => new InstallationTokens(), []);
$container->set('repositoryWebhooks', fn (VcsFactory $vcsFactory) => new RepositoryWebhooks($vcsFactory), ['vcsFactory']);

$container->set('vcsProviders', fn (VcsFactory $vcsFactory) => fn () => $vcsFactory->getProviders(), ['vcsFactory']);

$container->set('vcsConfigured', fn (VcsFactory $vcsFactory) => fn (string $provider) => $vcsFactory->isConfigured($provider), ['vcsFactory']);

$container->set('vcsWebhookSecret', fn (VcsFactory $vcsFactory) => fn (string $provider) => $vcsFactory->getWebhookSecret($provider), ['vcsFactory']);

$container->set('plan', fn () => []);

$container->set('smsRates', fn () => []);

$container->set(
    'getIsResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);
