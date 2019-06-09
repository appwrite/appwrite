<?php

global $utopia, $request, $response, $register, $project;

use Utopia\Exception;
use Storage\Devices\Local;
use Storage\Storage;

$utopia->get('/v1/health')
    ->desc('Check DB Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getDB')
    ->label('docs', false)
    ->action(
        function() use ($response, $register)
        {
            $response->json(array('OK'));
        }
    );

$utopia->get('/v1/health/db')
    ->desc('Check DB Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getDB')
    ->label('docs', false)
    ->action(
        function() use ($response, $register)
        {
            $register->get('db'); /* @var $db PDO */

            $response->json(array('OK'));
        }
    );

$utopia->get('/v1/health/cache')
    ->desc('Check Cache Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getCache')
    ->label('docs', false)
    ->action(
        function() use ($response, $register)
        {
            $register->get('cache'); /* @var $cache Predis\Client */

            $response->json(array('OK'));
        }
    );

$utopia->get('/v1/health/time')
    ->desc('Check Webhooks Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getTime')
    ->label('docs', false)
    ->action(
        function() use ($response)
        {
            /**
             * Code from: @see https://www.beliefmedia.com.au/query-ntp-time-server
             */
            $host = 'time.google.com'; // https://developers.google.com/time/
            $gap = 60; // Allow [X] seconds gap

            /* Create a socket and connect to NTP server */
            $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            socket_connect($sock, $host, 123);

            /* Send request */
            $msg = "\010" . str_repeat("\0", 47);

            socket_send($sock, $msg, strlen($msg), 0);

            /* Receive response and close socket */
            socket_recv($sock, $recv, 48, MSG_WAITALL);
            socket_close($sock);

            /* Interpret response */
            $data = unpack('N12', $recv);
            $timestamp = sprintf('%u', $data[9]);

            /* NTP is number of seconds since 0000 UT on 1 January 1900
               Unix time is seconds since 0000 UT on 1 January 1970 */
            $timestamp -= 2208988800;

            $diff = ($timestamp - time());

            if($diff > $gap || $diff < ($gap * -1)) {
                throw new Exception('Server time gaps detected');
            }

            $response->json(['remote' => $timestamp, 'local' => time(), 'diff' => $diff]);
        }
    );

$utopia->get('/v1/health/webhooks')
    ->desc('Check Time Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getWebhooks')
    ->label('docs', false)
    ->action(
        function() use ($response)
        {
            $response->json(['size' => Resque::size('webhooks')]);
        }
    );

$utopia->get('/v1/health/storage/local')
    ->desc('Check File System Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getStorageLocal')
    ->label('docs', false)
    ->action(
        function() use ($response)
        {
            $device = new Local();

            if(!is_readable($device->getRoot())) {
                throw new Exception('Device is not readable');
            }

            if(!is_writable($device->getRoot())) {
                throw new Exception('Device is not writable');
            }

            $response->json(array('OK'));
        }
    );

$utopia->get('/v1/health/storage/anti-virus')
    ->desc('Check Anti virus Health')
    ->label('scope', 'health.read')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getStorageAntiVirus')
    ->label('docs', false)
    ->action(
        function() use ($response)
        {
            $antiVirus = new \ClamAV\Network('clamav', 3310);

            $response->json([
                'status' => (@$antiVirus->ping()) ? 'online' : 'offline',
                'version' => @$antiVirus->version(),
            ]);
        }
    );

$utopia->get('/v1/health/stats')
    ->desc('System Stats')
    ->label('scope', 'god')
    ->label('sdk.namespace', 'health')
    ->label('sdk.method', 'getStats')
    ->label('docs', false)
    ->action(
        function() use ($request, $response, $register, $project)
        {
            $device = Storage::getDevice('local');
            $cache  = $register->get('cache');

            $cacheStats = $cache->info();

            $response
                ->json([
                    'server' => [
                        'name' => 'nginx',
                        'version' => shell_exec('nginx -v 2>&1'),
                    ],
                    'storage' => [
                        'used'              => $device->human($device->getDirectorySize($device->getRoot() . '/')),
                        'partitionTotal'    => $device->human($device->getPartitionTotalSpace()),
                        'partitionFree'     => $device->human($device->getPartitionFreeSpace()),
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
                    ]
                ]);
        }
    );