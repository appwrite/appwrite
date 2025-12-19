<?php

use Appwrite\ClamAV\Network;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\StatsResources;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Document;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Domain;
use Utopia\Validator\Integer;
use Utopia\Validator\Multiple;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

App::get('/v1/health')
    ->desc('Get HTTP')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'get',
        description: '/docs/references/health/get.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->action(function (Response $response) {

        $output = [
            'name' => 'http',
            'status' => 'pass',
            'ping' => 0
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

App::get('/v1/health/version')
    ->desc('Get version')
    ->groups(['api', 'health'])
    ->label('scope', 'public')
    ->inject('response')
    ->action(function (Response $response) {
        $response->dynamic(new Document([ 'version' => APP_VERSION_STABLE ]), Response::MODEL_HEALTH_VERSION);
    });

App::get('/v1/health/db')
    ->desc('Get DB')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getDB',
        description: '/docs/references/health/get-db.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->inject('pools')
    ->action(function (Response $response, Group $pools) {
        $output = [];
        $failures = [];

        $configs = [
            'Console.DB' => Config::getParam('pools-console'),
            'Projects.DB' => Config::getParam('pools-database'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $database) {
                try {
                    $adapter = new DatabasePool($pools->get($database));

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($database)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000)
                        ]);
                    } else {
                        $failures[] = $database;
                    }
                } catch (\Throwable) {
                    $failures[] = $database;
                }
            }
        }

        // Only throw error if ALL databases failed (no successful pings)
        // This allows partial failures in environments where not all DBs are ready
        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'DB failure on: ' . implode(", ", $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    });

App::get('/v1/health/cache')
    ->desc('Get cache')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getCache',
        description: '/docs/references/health/get-cache.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->inject('pools')
    ->action(function (Response $response, Group $pools) {
        $output = [];
        $failures = [];

        $configs = [
            'Cache' => Config::getParam('pools-cache'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $cache) {
                try {
                    $adapter = new CachePool($pools->get($cache));

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($cache)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000)
                        ]);
                    } else {
                        $failures[] = $cache;
                    }
                } catch (\Throwable) {
                    $failures[] = $cache;
                }
            }
        }

        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Cache failure on: ' . implode(", ", $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    });

App::get('/v1/health/pubsub')
    ->desc('Get pubsub')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getPubSub',
        description: '/docs/references/health/get-pubsub.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->inject('pools')
    ->action(function (Response $response, Group $pools) {
        $output = [];
        $failures = [];

        $configs = [
            'PubSub' => Config::getParam('pools-pubsub'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $pubsub) {
                try {
                    $adapter = new PubSubPool($pools->get($pubsub));

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($pubsub)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000)
                        ]);
                    } else {
                        $failures[] = $pubsub;
                    }
                } catch (\Throwable) {
                    $failures[] = $pubsub;
                }
            }
        }

        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Pubsub failure on: ' . implode(", ", $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    });

App::get('/v1/health/time')
    ->desc('Get time')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getTime',
        description: '/docs/references/health/get-time.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_TIME,
            )
        ],
        contentType: ContentType::JSON
    ))
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

App::get('/v1/health/queue/webhooks')
    ->desc('Get webhooks queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueWebhooks',
        description: '/docs/references/health/get-queue-webhooks.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForWebhooks')
    ->inject('response')
    ->action(function (int|string $threshold, Webhook $queueForWebhooks, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForWebhooks->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/logs')
    ->desc('Get logs queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueLogs',
        description: '/docs/references/health/get-queue-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForAudits')
    ->inject('response')
    ->action(function (int|string $threshold, Audit $queueForAudits, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForAudits->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/certificate')
    ->desc('Get the SSL certificate for a domain')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getCertificate',
        description: '/docs/references/health/get-certificate.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_CERTIFICATE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('domain', null, new Multiple([new AnyOf([new URL(), new Domain()]), new PublicDomain()]), Multiple::TYPE_STRING, 'Domain name')
    ->inject('response')
    ->action(function (string $domain, Response $response) {
        if (filter_var($domain, FILTER_VALIDATE_URL)) {
            $domain = parse_url($domain, PHP_URL_HOST);
        }

        $sslContext = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true
            ]
        ]);
        $sslSocket = stream_socket_client("ssl://" . $domain . ":443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContext);
        if (!$sslSocket) {
            throw new Exception(Exception::HEALTH_INVALID_HOST);
        }

        $streamContextParams = stream_context_get_params($sslSocket);
        $peerCertificate = $streamContextParams['options']['ssl']['peer_certificate'];
        $certificatePayload = openssl_x509_parse($peerCertificate);


        $sslExpiration = $certificatePayload['validTo_time_t'];
        $status = $sslExpiration < time() ? 'fail' : 'pass';

        if ($status === 'fail') {
            throw new Exception(Exception::HEALTH_CERTIFICATE_EXPIRED);
        }

        $response->dynamic(new Document([
            'name' => $certificatePayload['name'],
            'subjectSN' => $certificatePayload['subject']['CN'],
            'issuerOrganisation' => $certificatePayload['issuer']['O'],
            'validFrom' => $certificatePayload['validFrom_time_t'],
            'validTo' => $certificatePayload['validTo_time_t'],
            'signatureTypeSN' => $certificatePayload['signatureTypeSN'],
        ]), Response::MODEL_HEALTH_CERTIFICATE);
    });

App::get('/v1/health/queue/certificates')
    ->desc('Get certificates queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueCertificates',
        description: '/docs/references/health/get-queue-certificates.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForCertificates')
    ->inject('response')
    ->action(function (int|string $threshold, Certificate $queueForCertificates, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForCertificates->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/builds')
    ->desc('Get builds queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueBuilds',
        description: '/docs/references/health/get-queue-builds.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForBuilds')
    ->inject('response')
    ->action(function (int|string $threshold, Build $queueForBuilds, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForBuilds->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/databases')
    ->desc('Get databases queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueDatabases',
        description: '/docs/references/health/get-queue-databases.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('name', 'database_db_main', new Text(256), 'Queue name for which to check the queue size', true)
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForDatabase')
    ->inject('response')
    ->action(function (string $name, int|string $threshold, Database $queueForDatabase, Response $response) {
        $threshold = \intval($threshold);
        $size = $queueForDatabase->setQueue($name)->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/deletes')
    ->desc('Get deletes queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueDeletes',
        description: '/docs/references/health/get-queue-deletes.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForDeletes')
    ->inject('response')
    ->action(function (int|string $threshold, Delete $queueForDeletes, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForDeletes->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/mails')
    ->desc('Get mails queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueMails',
        description: '/docs/references/health/get-queue-mails.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForMails')
    ->inject('response')
    ->action(function (int|string $threshold, Mail $queueForMails, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForMails->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/messaging')
    ->desc('Get messaging queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueMessaging',
        description: '/docs/references/health/get-queue-messaging.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (int|string $threshold, Messaging $queueForMessaging, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForMessaging->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/migrations')
    ->desc('Get migrations queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueMigrations',
        description: '/docs/references/health/get-queue-migrations.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForMigrations')
    ->inject('response')
    ->action(function (int|string $threshold, Migration $queueForMigrations, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForMigrations->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/functions')
    ->desc('Get functions queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueFunctions',
        description: '/docs/references/health/get-queue-functions.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForFunctions')
    ->inject('response')
    ->action(function (int|string $threshold, Func $queueForFunctions, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForFunctions->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/stats-resources')
    ->desc('Get stats resources queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueStatsResources',
        description: '/docs/references/health/get-queue-stats-resources.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForStatsResources')
    ->inject('response')
    ->action(function (int|string $threshold, StatsResources $queueForStatsResources, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForStatsResources->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/queue/stats-usage')
    ->desc('Get stats usage queue')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getQueueUsage',
        description: '/docs/references/health/get-queue-stats-usage.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('queueForStatsUsage')
    ->inject('response')
    ->action(function (int|string $threshold, StatsUsage $queueForStatsUsage, Response $response) {
        $threshold = \intval($threshold);

        $size = $queueForStatsUsage->getSize();

        if ($size >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue size threshold hit. Current size is {$size} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $size ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/storage/local')
    ->desc('Get local storage')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'storage',
        name: 'getStorageLocal',
        description: '/docs/references/health/get-storage-local.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
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
            'ping' => \round((\microtime(true) - $checkStart) * 1000)
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

App::get('/v1/health/storage')
    ->desc('Get storage')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'storage',
        name: 'getStorage',
        description: '/docs/references/health/get-storage.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_STATUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->inject('deviceForFiles')
    ->inject('deviceForFunctions')
    ->inject('deviceForSites')
    ->inject('deviceForBuilds')
    ->action(function (Response $response, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForSites, Device $deviceForBuilds) {
        $devices = [$deviceForFiles, $deviceForFunctions, $deviceForSites,  $deviceForBuilds];
        $checkStart = \microtime(true);

        foreach ($devices as $device) {
            $uniqueFileName = \uniqid('health', true);
            $filePath = $device->getPath($uniqueFileName);

            if (!$device->write($filePath, 'test', 'text/plain')) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed writing test file to ' . $device->getRoot());
            }

            if ($device->read($filePath) !== 'test') {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed reading test file from ' . $device->getRoot());
            }

            if (!$device->delete($filePath)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed deleting test file from ' . $device->getRoot());
            }
        }

        $output = [
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) * 1000)
        ];

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_STATUS);
    });

App::get('/v1/health/anti-virus')
    ->desc('Get antivirus')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'health',
        name: 'getAntivirus',
        description: '/docs/references/health/get-storage-anti-virus.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_ANTIVIRUS,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->action(function (Response $response) {

        $output = [
            'status' => '',
            'version' => ''
        ];

        if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'disabled') { // Check if scans are enabled
            $output['status'] = 'disabled';
            $output['version'] = '';
        } else {
            $antivirus = new Network(
                System::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                (int) System::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
            );

            try {
                $output['version'] = @$antivirus->version();
                $output['status'] = (@$antivirus->ping()) ? 'pass' : 'fail';
            } catch (\Throwable $e) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Antivirus is not available');
            }
        }

        $response->dynamic(new Document($output), Response::MODEL_HEALTH_ANTIVIRUS);
    });

App::get('/v1/health/queue/failed/:name')
    ->desc('Get number of failed queue jobs')
    ->groups(['api', 'health'])
    ->label('scope', 'health.read')
    ->label('sdk', new Method(
        namespace: 'health',
        group: 'queue',
        name: 'getFailedJobs',
        description: '/docs/references/health/get-failed-queue-jobs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_HEALTH_QUEUE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('name', '', new WhiteList([
        Event::DATABASE_QUEUE_NAME,
        Event::DELETE_QUEUE_NAME,
        Event::AUDITS_QUEUE_NAME,
        Event::MAILS_QUEUE_NAME,
        Event::FUNCTIONS_QUEUE_NAME,
        Event::STATS_RESOURCES_QUEUE_NAME,
        Event::STATS_USAGE_QUEUE_NAME,
        Event::WEBHOOK_QUEUE_NAME,
        Event::CERTIFICATES_QUEUE_NAME,
        Event::BUILDS_QUEUE_NAME,
        Event::MESSAGING_QUEUE_NAME,
        Event::MIGRATIONS_QUEUE_NAME
    ]), 'The name of the queue')
    ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
    ->inject('response')
    ->inject('queueForDatabase')
    ->inject('queueForDeletes')
    ->inject('queueForAudits')
    ->inject('queueForMails')
    ->inject('queueForFunctions')
    ->inject('queueForStatsResources')
    ->inject('queueForStatsUsage')
    ->inject('queueForWebhooks')
    ->inject('queueForCertificates')
    ->inject('queueForBuilds')
    ->inject('queueForMessaging')
    ->inject('queueForMigrations')
    ->action(function (
        string $name,
        int|string $threshold,
        Response $response,
        Database $queueForDatabase,
        Delete $queueForDeletes,
        Audit $queueForAudits,
        Mail $queueForMails,
        Func $queueForFunctions,
        StatsResources $queueForStatsResources,
        StatsUsage $queueForStatsUsage,
        Webhook $queueForWebhooks,
        Certificate $queueForCertificates,
        Build $queueForBuilds,
        Messaging $queueForMessaging,
        Migration $queueForMigrations
    ) {
        $threshold = \intval($threshold);

        /** @var Event $queue */
        $queue = match ($name) {
            Event::DATABASE_QUEUE_NAME => $queueForDatabase,
            Event::DELETE_QUEUE_NAME => $queueForDeletes,
            Event::AUDITS_QUEUE_NAME => $queueForAudits,
            Event::MAILS_QUEUE_NAME => $queueForMails,
            Event::FUNCTIONS_QUEUE_NAME => $queueForFunctions,
            Event::STATS_RESOURCES_QUEUE_NAME => $queueForStatsResources,
            Event::STATS_USAGE_QUEUE_NAME => $queueForStatsUsage,
            Event::WEBHOOK_QUEUE_NAME => $queueForWebhooks,
            Event::CERTIFICATES_QUEUE_NAME => $queueForCertificates,
            Event::BUILDS_QUEUE_NAME => $queueForBuilds,
            Event::MESSAGING_QUEUE_NAME => $queueForMessaging,
            Event::MIGRATIONS_QUEUE_NAME => $queueForMigrations,
        };
        $failed = $queue->getSize(failed: true);

        if ($failed >= $threshold) {
            throw new Exception(Exception::HEALTH_QUEUE_SIZE_EXCEEDED, "Queue failed jobs threshold hit. Current size is {$failed} and threshold is {$threshold}.");
        }

        $response->dynamic(new Document([ 'size' => $failed ]), Response::MODEL_HEALTH_QUEUE);
    });

App::get('/v1/health/stats') // Currently only used internally
->desc('Get system stats')
    ->groups(['api', 'health'])
    ->label('scope', 'root')
    ->label('docs', false)
    ->inject('response')
    ->inject('register')
    ->inject('deviceForFiles')
    ->action(function (Response $response, Registry $register, Device $deviceForFiles) {

        $cache = $register->get('cache');

        $cacheStats = $cache->info();

        $response
            ->json([
                'storage' => [
                    'used' => Storage::human($deviceForFiles->getDirectorySize($deviceForFiles->getRoot() . '/')),
                    'partitionTotal' => Storage::human($deviceForFiles->getPartitionTotalSpace()),
                    'partitionFree' => Storage::human($deviceForFiles->getPartitionFreeSpace()),
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
