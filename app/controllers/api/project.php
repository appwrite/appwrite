<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DateTimeValidator;
use Utopia\Validator\WhiteList;

App::get('/v1/project/usage')
    ->desc('Get project usage stats')
    ->groups(['api', 'usage'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_PROJECT)
    ->param('startDate', '', new DateTimeValidator(), 'Starting date for the usage')
    ->param('endDate', '', new DateTimeValidator(), 'End date for the usage')
    ->param('period', '1d', new WhiteList(['1h', '1d']), 'Period used', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $startDate, string $endDate, string $period, Response $response, Database $dbForProject) {
        $stats = $total = $usage = [];
        $format = 'Y-m-d 00:00:00';
        $firstDay = (new DateTime($startDate))->format($format);
        $lastDay = (new DateTime($endDate))->format($format);

        $metrics = [
            'total' => [
                METRIC_EXECUTIONS,
                METRIC_EXECUTIONS_MB_SECONDS,
                METRIC_BUILDS_MB_SECONDS,
                METRIC_DOCUMENTS,
                METRIC_DATABASES,
                METRIC_USERS,
                METRIC_BUCKETS,
                METRIC_FILES_STORAGE,
                METRIC_DATABASES_STORAGE,
                METRIC_DEPLOYMENTS_STORAGE,
                METRIC_BUILDS_STORAGE
            ],
            'period' => [
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
                METRIC_USERS,
                METRIC_EXECUTIONS,
                METRIC_DATABASES_STORAGE,
                METRIC_EXECUTIONS_MB_SECONDS,
                METRIC_BUILDS_MB_SECONDS
            ]
        ];

        $factor = match ($period) {
            '1h' => 3600,
            '1d' => 86400,
        };

        $limit = match ($period) {
            '1h' => (new DateTime($startDate))->diff(new DateTime($endDate))->days * 24,
            '1d' => (new DateTime($startDate))->diff(new DateTime($endDate))->days
        };

        $format = match ($period) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        Authorization::skip(function () use ($dbForProject, $firstDay, $lastDay, $period, $metrics, $limit, &$total, &$stats) {
            foreach ($metrics['total'] as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);
                $total[$metric] = $result['value'] ?? 0;
            }

            foreach ($metrics['period'] as $metric) {
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::greaterThanEqual('time', $firstDay),
                    Query::lessThan('time', $lastDay),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);

                $stats[$metric] = [];
                foreach ($results as $result) {
                    $stats[$metric][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $now = time();
        foreach ($metrics['period'] as $metric) {
            $usage[$metric] = [];
            $leap = $now - ($limit * $factor);
            while ($leap < $now) {
                $leap += $factor;
                $formatDate = date($format, $leap);
                $usage[$metric][] = [
                    'value' => $stats[$metric][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $executionsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        $executionsMbSecondsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_MB_SECONDS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        $buildsMbSecondsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_MB_SECONDS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        $bucketsBreakdown = array_map(function ($bucket) use ($dbForProject) {
            $id = $bucket->getId();
            $name = $bucket->getAttribute('name');
            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('buckets'));

        $databasesStorageBreakdown = array_map(function ($database) use ($dbForProject) {
            $id = $database->getId();
            $name = $database->getAttribute('name');
            $metric = str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_STORAGE);

            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('databases'));

        $functionsStorageBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $deploymentMetric = str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $function->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE);
            $deploymentValue = $dbForProject->findOne('stats', [
                Query::equal('metric', [$deploymentMetric]),
                Query::equal('period', ['inf'])
            ]);

            $buildMetric = str_replace(['{functionInternalId}'], [$function->getInternalId()], METRIC_FUNCTION_ID_BUILDS_STORAGE);
            $buildValue = $dbForProject->findOne('stats', [
                Query::equal('metric', [$buildMetric]),
                Query::equal('period', ['inf'])
            ]);

            $value = ($buildValue['value'] ?? 0) + ($deploymentValue['value'] ?? 0);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value,
            ];
        }, $dbForProject->find('functions'));

        $executionsMbSecondsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_MB_SECONDS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        $buildsMbSecondsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_MB_SECONDS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        // merge network inbound + outbound
        $projectBandwidth = [];
        foreach ($usage[METRIC_NETWORK_INBOUND] as $item) {
            $projectBandwidth[$item['date']] ??= 0;
            $projectBandwidth[$item['date']] += $item['value'];
        }

        foreach ($usage[METRIC_NETWORK_OUTBOUND] as $item) {
            $projectBandwidth[$item['date']] ??= 0;
            $projectBandwidth[$item['date']] += $item['value'];
        }


        $network = [];
        foreach ($projectBandwidth as $date => $value) {
            $network[] = [
                'date' => $date,
                'value' => $value
            ];
        }

        $response->dynamic(new Document([
            'requests' => ($usage[METRIC_NETWORK_REQUESTS]),
            'network' => $network,
            'users' => ($usage[METRIC_USERS]),
            'executions' => ($usage[METRIC_EXECUTIONS]),
            'executionsTotal' => $total[METRIC_EXECUTIONS],
            'executionsMbSecondsTotal' => $total[METRIC_EXECUTIONS_MB_SECONDS],
            'buildsMbSecondsTotal' => $total[METRIC_BUILDS_MB_SECONDS],
            'documentsTotal' => $total[METRIC_DOCUMENTS],
            'databasesTotal' => $total[METRIC_DATABASES],
            'databasesStorageTotal' => $total[METRIC_DATABASES_STORAGE],
            'usersTotal' => $total[METRIC_USERS],
            'bucketsTotal' => $total[METRIC_BUCKETS],
            'filesStorageTotal' => $total[METRIC_FILES_STORAGE],
            'functionsStorageTotal' => $total[METRIC_DEPLOYMENTS_STORAGE] + $total[METRIC_BUILDS_STORAGE],
            'buildsStorageTotal' => $total[METRIC_BUILDS_STORAGE],
            'deploymentsStorageTotal' => $total[METRIC_DEPLOYMENTS_STORAGE],
            'executionsBreakdown' => $executionsBreakdown,
            'executionsMbSecondsBreakdown' => $executionsMbSecondsBreakdown,
            'buildsMbSecondsBreakdown' => $buildsMbSecondsBreakdown,
            'bucketsBreakdown' => $bucketsBreakdown,
            'databasesStorageBreakdown' => $databasesStorageBreakdown,
            'executionsMbSecondsBreakdown' => $executionsMbSecondsBreakdown,
            'buildsMbSecondsBreakdown' => $buildsMbSecondsBreakdown,
            'functionsStorageBreakdown' => $functionsStorageBreakdown,
        ]), Response::MODEL_USAGE_PROJECT);
    });
