<?php

global $utopia, $request, $response, $register, $project;

use Utopia\Exception;
use Appwrite\Storage\Device\Local;
use Appwrite\Storage\Storage;
use Appwrite\ClamAV\Network;

$utopia->get('/v1/health')
    ->desc('Get HTTP')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/health/get.md')
    ->action(
        function () use ($response) {
            $response->json(['status' => 'OK']);
        }
    );

$utopia->get('/v1/health/version')
    ->desc('Get Version')
    ->groups(['api', 'health'])
    ->label('scope', 'public')
    ->action(
        function () use ($response) {
            $response->json(['version' => APP_VERSION_STABLE]);
        }
    );

$utopia->get('/v1/health/db')
    ->desc('Get DB')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getDB')
    ->label('sdk.description', '/docs/references/health/get-db.md')
    ->action(
        function () use ($response, $register) {
            $register->get('db'); /* @var $db PDO */

            $response->json(['status' => 'OK']);
        }
    );

$utopia->get('/v1/health/cache')
    ->desc('Get Cache')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getCache')
    ->label('sdk.description', '/docs/references/health/get-cache.md')
    ->action(
        function () use ($response, $register) {
            $register->get('cache'); /* @var $cache Predis\Client */

            $response->json(['status' => 'OK']);
        }
    );

$utopia->get('/v1/health/time')
    ->desc('Get Time')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getTime')
    ->label('sdk.description', '/docs/references/health/get-time.md')
    ->action(
        function () use ($response) {
            /*
             * Code from: @see https://www.beliefmedia.com.au/query-ntp-time-server
             */
            $host = 'time.google.com'; // https://developers.google.com/time/
            $gap = 60; // Allow [X] seconds gap

            /* Create a socket and connect to NTP server */
            $sock = \socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            \socket_connect($sock, $host, 123);

            /* Send request */
            $msg = "\010".\str_repeat("\0", 47);

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
                throw new Exception('Server time gaps detected');
            }

            $response->json(['remote' => $timestamp, 'local' => \time(), 'diff' => $diff]);
        }
    );

$utopia->get('/v1/health/queue/webhooks')
    ->desc('Get Webhooks Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueWebhooks')
    ->label('sdk.description', '/docs/references/health/get-queue-webhooks.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-webhooks')]);
        }
    );

$utopia->get('/v1/health/queue/tasks')
    ->desc('Get Tasks Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueTasks')
    ->label('sdk.description', '/docs/references/health/get-queue-tasks.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-tasks')]);
        }
    );

$utopia->get('/v1/health/queue/logs')
    ->desc('Get Logs Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueLogs')
    ->label('sdk.description', '/docs/references/health/get-queue-logs.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-audit')]);
        }
    );

$utopia->get('/v1/health/queue/usage')
    ->desc('Get Usage Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueUsage')
    ->label('sdk.description', '/docs/references/health/get-queue-usage.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-usage')]);
        }
    );

$utopia->get('/v1/health/queue/certificates')
    ->desc('Get Certificate Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueCertificates')
    ->label('sdk.description', '/docs/references/health/get-queue-certificates.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-certificates')]);
        }
    );

$utopia->get('/v1/health/queue/functions')
    ->desc('Get Functions Queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getQueueFunctions')
    ->label('sdk.description', '/docs/references/health/get-queue-functions.md')
    ->action(
        function () use ($response) {
            $response->json(['size' => Resque::size('v1-functions')]);
        }
    );

$utopia->get('/v1/health/storage/local')
    ->desc('Get Local Storage')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getStorageLocal')
    ->label('sdk.description', '/docs/references/health/get-storage-local.md')
    ->action(
        function () use ($response) {
            foreach ([
                'Uploads' => APP_STORAGE_UPLOADS,
                'Cache' => APP_STORAGE_CACHE,
                'Config' => APP_STORAGE_CONFIG,
                'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume) {
                $device = new Local($volume);

                if (!\is_readable($device->getRoot())) {
                    throw new Exception('Device '.$key.' dir is not readable');
                }

                if (!\is_writable($device->getRoot())) {
                    throw new Exception('Device '.$key.' dir is not writable');
                }
            }

            $response->json(['status' => 'OK']);
        }
    );

$utopia->get('/v1/health/anti-virus')
    ->desc('Get Anti virus')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getAntiVirus')
    ->label('sdk.description', '/docs/references/health/get-storage-anti-virus.md')
    ->action(
        function () use ($request, $response) {
            if ($request->getServer('_APP_STORAGE_ANTIVIRUS') === 'disabled') { // Check if scans are enabled
                throw new Exception('Anitvirus is disabled');
            }

            $antiVirus = new Network('clamav', 3310);

            $response->json([
                'status' => (@$antiVirus->ping()) ? 'online' : 'offline',
                'version' => @$antiVirus->version(),
            ]);
        }
    );

$utopia->get('/v1/health/stats') // Currently only used internally
    ->desc('Get System Stats')
    ->groups(['api', 'health'])
    ->label('scope', 'god')
    // ->label('sdk.platform', [APP_PLATFORM_SERVER])
    // ->label('sdk.namespace', 'health')
    // ->label('sdk.method', 'getStats')
    ->label('docs', false)
    ->action(
        function () use ($response, $register) {
            $device = Storage::getDevice('local');
            $cache = $register->get('cache');

            $cacheStats = $cache->info();

            $response
                ->json([
                    'server' => [
                        'name' => 'nginx',
                        'version' => \shell_exec('nginx -v 2>&1'),
                    ],
                    'storage' => [
                        'used' => Storage::human($device->getDirectorySize($device->getRoot().'/')),
                        'partitionTotal' => Storage::human($device->getPartitionTotalSpace()),
                        'partitionFree' => Storage::human($device->getPartitionFreeSpace()),
                    ],
                    'cache' => [
                        'uptime' => (isset($cacheStats['uptime_in_seconds'])) ? $cacheStats['uptime_in_seconds'] : 0,
                        'clients' => (isset($cacheStats['connected_clients'])) ? $cacheStats['connected_clients'] : 0,
                        'hits' => (isset($cacheStats['keyspace_hits'])) ? $cacheStats['keyspace_hits'] : 0,
                        'misses' => (isset($cacheStats['keyspace_misses'])) ? $cacheStats['keyspace_misses'] : 0,
                        'memory_used' => (isset($cacheStats['used_memory'])) ? $cacheStats['used_memory'] : 0,
                        'memory_used_human' => (isset($cacheStats['used_memory_human'])) ? $cacheStats['used_memory_human'] : 0,
                        'memory_used_peak' => (isset($cacheStats['used_memory_peak'])) ? $cacheStats['used_memory_peak'] : 0,
                        'memory_used_peak_human' => (isset($cacheStats['used_memory_peak_human'])) ? $cacheStats['used_memory_peak_human'] : 0,
                    ],
                ]);
        }
    );
