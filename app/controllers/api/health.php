<?php

use Appwrite\ClamAV\Network;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Http\Http;
use Utopia\Database\Document;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;

Http::get('/v1/health')
    ->desc('Get HTTP')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/health/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_STATUS)
    ->inject('response')
    ->action(function (Response $response) {

        $output = [
            'status' => 'pass',
            'ping' => 0
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

Http::get('/v1/health/version')
    ->desc('Get Version')
    ->groups(['api', 'health'])
    ->label('scope', 'public')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_VERSION)
    ->inject('response')
    ->action(function (Response $response) {

        $response->dynamic(new Document([ 'version' => APP_VERSION_STABLE ]), Response::MODEL_HEALTH_VERSION);
    });

Http::get('/v1/health/db')
    ->desc('Get DB')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getDB')
    ->label('sdk.description', '/docs/references/health/get-db.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_STATUS)
    ->inject('response')
    ->inject('utopia')
    ->action(function (Response $response, Http $http) {

        $checkStart = \microtime(true);

        try {
            $db = $http->getResource('db'); /* @var $db PDO */

            // Run a small test to check the connection
            $statement = $db->prepare("SELECT 1;");

            $statement->closeCursor();

            $statement->execute();
        } catch (Exception $_e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Database is not available');
        }

        $output = [
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) / 1000)
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

Http::get('/v1/health/cache')
    ->desc('Get Cache')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getCache')
    ->label('sdk.description', '/docs/references/health/get-cache.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_STATUS)
    ->inject('response')
    ->inject('utopia')
    ->action(function (Response $response, Http $http) {

        $checkStart = \microtime(true);

        $redis = $http->getResource('cache');

        if (!$redis->ping(true)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Cache is not available');
        }

        $output = [
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) / 1000)
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

Http::get('/v1/health/time')
    ->desc('Get Time')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getTime')
    ->label('sdk.description', '/docs/references/health/get-time.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_TIME)
    ->inject('response')
    ->action(function (Response $response) {

        /*
         * Code from: @see https://www.beliefmedia.com.au/query-ntp-time-server
         */
        $host = 'time.google.com'; // https://developers.google.com/time/
        $gap = 60; // Allow [X] seconds gap

        /* Create a socket and connect to NTP server */
        $sock = \socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        \socket_connect($sock, $host, 123);

        /* Send request */
        $msg = "\010" . \str_repeat("\0", 47);

        \socket_send($sock, $msg, \strlen($msg), 0);

        /* Receive response and close socket */
        \socket_recv($sock, $recv, 48, MSG_WAITALL);
        \socket_close($sock);

        /* Interpret response */
        $data = \unpack('N12', $recv);
        $timestamp = \sprintf('%u', $data[9]);

        /* NTP is number of seconds since 0000 UT on 1 January 1900
            Unix time is seconds since 0000 UT on 1 January 1970 */
        $timestamp -= 2208988800;

        $diff = ($timestamp - \time());

        if ($diff > $gap || $diff < ($gap * -1)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Server time gaps detected');
        }

        $output = [
            'remoteTime' => $timestamp,
            'localTime' => \time(),
            'diff' => $diff
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_TIME);
    });

Http::get('/v1/health/queue/webhooks')
    ->desc('Get Webhooks Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueWebhooks')
    ->label('sdk.description', '/docs/references/health/get-queue-webhooks.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_QUEUE)
    ->inject('response')
    ->action(function (Response $response) {

        $response->dynamic(new Document([ 'size' => Resque::size(Event::WEBHOOK_QUEUE_NAME) ]), Response::MODEL_HEALTH_QUEUE);
    }, ['response']);

Http::get('/v1/health/queue/logs')
    ->desc('Get Logs Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueLogs')
    ->label('sdk.description', '/docs/references/health/get-queue-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_QUEUE)
    ->inject('response')
    ->action(function (Response $response) {

        $response->dynamic(new Document([ 'size' => Resque::size(Event::AUDITS_QUEUE_NAME) ]), Response::MODEL_HEALTH_QUEUE);
    }, ['response']);

Http::get('/v1/health/queue/certificates')
    ->desc('Get Certificates Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueCertificates')
    ->label('sdk.description', '/docs/references/health/get-queue-certificates.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_QUEUE)
    ->inject('response')
    ->action(function (Response $response) {

        $response->dynamic(new Document([ 'size' => Resque::size(Event::CERTIFICATES_QUEUE_NAME) ]), Response::MODEL_HEALTH_QUEUE);
    }, ['response']);

Http::get('/v1/health/queue/functions')
    ->desc('Get Functions Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueFunctions')
    ->label('sdk.description', '/docs/references/health/get-queue-functions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_QUEUE)
    ->inject('response')
    ->action(function (Response $response) {

        $response->dynamic(new Document([ 'size' => Resque::size(Event::FUNCTIONS_QUEUE_NAME) ]), Response::MODEL_HEALTH_QUEUE);
    }, ['response']);

Http::get('/v1/health/storage/local')
    ->desc('Get Local Storage')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getStorageLocal')
    ->label('sdk.description', '/docs/references/health/get-storage-local.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_STATUS)
    ->inject('response')
    ->action(function (Response $response) {

        $checkStart = \microtime(true);

        foreach (
            [
            'Uploads' => APP_STORAGE_UPLOADS,
            'Cache' => APP_STORAGE_CACHE,
            'Config' => APP_STORAGE_CONFIG,
            'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume
        ) {
            $device = new Local($volume);

            if (!\is_readable($device->getRoot())) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Device ' . $key . ' dir is not readable');
            }

            if (!\is_writable($device->getRoot())) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Device ' . $key . ' dir is not writable');
            }
        }

        $output = [
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) / 1000)
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

Http::get('/v1/health/anti-virus')
    ->desc('Get Antivirus')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getAntivirus')
    ->label('sdk.description', '/docs/references/health/get-storage-anti-virus.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_HEALTH_ANTIVIRUS)
    ->inject('response')
    ->action(function (Response $response) {

        $output = [
            'status' => '',
            'version' => ''
        ];

        if (Http::getEnv('_APP_STORAGE_ANTIVIRUS') === 'disabled') { // Check if scans are enabled
            $output['status'] = 'disabled';
            $output['version'] = '';
        } else {
            $antivirus = new Network(
                Http::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                (int) Http::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
            );

            try {
                $output['version'] = @$antivirus->version();
                $output['status'] = (@$antivirus->ping()) ? 'pass' : 'fail';
            } catch (\Exception $e) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Antivirus is not available');
            }
        }

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_ANTIVIRUS);
    });

Http::get('/v1/health/stats') // Currently only used internally
    ->desc('Get System Stats')
    ->groups(['api', 'health'])
    ->label('scope', 'root')
    // ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    // ->label('sdk.namespace', 'health')
    // ->label('sdk.method', 'getStats')
    ->label('docs', false)
    ->inject('response')
    ->inject('register')
    ->inject('deviceFiles')
    ->action(function (Response $response, Registry $register, Device $deviceFiles) {

        $cache = $register->get('cache');

        $cacheStats = $cache->info();

        $response
            ->json([
                'storage' => [
                    'used' => Storage::human($deviceFiles->getDirectorySize($deviceFiles->getRoot() . '/')),
                    'partitionTotal' => Storage::human($deviceFiles->getPartitionTotalSpace()),
                    'partitionFree' => Storage::human($deviceFiles->getPartitionFreeSpace()),
                ],
                'cache' => [
                    'uptime' => $cacheStats['uptime_in_seconds'] ?? 0,
                    'clients' => $cacheStats['connected_clients'] ?? 0,
                    'hits' => $cacheStats['keyspace_hits'] ?? 0,
                    'misses' => $cacheStats['keyspace_misses'] ?? 0,
                    'memory_used' => $cacheStats['used_memory'] ?? 0,
                    'memory_used_human' => $cacheStats['used_memory_human'] ?? 0,
                    'memory_used_peak' => $cacheStats['used_memory_peak'] ?? 0,
                    'memory_used_peak_human' => $cacheStats['used_memory_peak_human'] ?? 0,
                ],
            ]);
    });
