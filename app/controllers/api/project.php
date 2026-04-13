<?php

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DateTimeValidator;
use Utopia\Http\Http;
use Utopia\Validator\WhiteList;

Http::get('/v1/project/usage')
    ->desc('Get project usage stats')
    ->groups(['api', 'usage'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'project',
        group: null,
        name: 'getUsage',
        description: '/docs/references/project/get-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_PROJECT,
            )
        ]
    ))
    ->param('startDate', '', new DateTimeValidator(), 'Starting date for the usage')
    ->param('endDate', '', new DateTimeValidator(), 'End date for the usage')
    ->param('period', '1d', new WhiteList(['1h', '1d']), 'Period used', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('getLogsDB')
    ->inject('smsRates')
    ->action(function (string $startDate, string $endDate, string $period, Response $response, Document $project, Database $dbForProject, Authorization $authorization, callable $getLogsDB, array $smsRates) {
        $stats = $total = $usage = [];
        $format = 'Y-m-d 00:00:00';
        $firstDay = (new DateTime($startDate))->format($format);
        $lastDay = (new DateTime($endDate))->format($format);

        $dbForLogs = call_user_func($getLogsDB, $project);

        $metrics = [
            'total' => [
                METRIC_EXECUTIONS,
                METRIC_EXECUTIONS_MB_SECONDS,
                METRIC_BUILDS_MB_SECONDS,
                METRIC_DOCUMENTS,
                METRIC_DOCUMENTS_DOCUMENTSDB,
                METRIC_DATABASES,
                METRIC_DATABASES_DOCUMENTSDB,
                METRIC_USERS,
                METRIC_BUCKETS,
                METRIC_FILES_STORAGE,
                METRIC_DATABASES_STORAGE,
                METRIC_DATABASES_STORAGE_DOCUMENTSDB,
                METRIC_DEPLOYMENTS_STORAGE,
                METRIC_BUILDS_STORAGE,
                METRIC_DATABASES_OPERATIONS_READS,
                METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB,
                METRIC_DATABASES_OPERATIONS_WRITES,
                METRIC_DATABASES_OPERATIONS_WRITES_DOCUMENTSDB,
                METRIC_FILES_IMAGES_TRANSFORMED,
                // VectorsDB totals
                METRIC_DATABASES_VECTORSDB,
                METRIC_COLLECTIONS_VECTORSDB,
                METRIC_DOCUMENTS_VECTORSDB,
                METRIC_DATABASES_STORAGE_VECTORSDB,
                METRIC_DATABASES_OPERATIONS_READS_VECTORSDB,
                METRIC_DATABASES_OPERATIONS_WRITES_VECTORSDB,
                // Embeddings totals
                METRIC_EMBEDDINGS_TEXT,
                METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS,
                METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION,
                METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR
            ],
            'period' => [
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
                METRIC_USERS,
                METRIC_EXECUTIONS,
                METRIC_DATABASES_STORAGE,
                METRIC_DATABASES_STORAGE_DOCUMENTSDB,
                METRIC_EXECUTIONS_MB_SECONDS,
                METRIC_BUILDS_MB_SECONDS,
                METRIC_DATABASES_OPERATIONS_READS,
                METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB,
                METRIC_DATABASES_OPERATIONS_WRITES,
                METRIC_DATABASES_OPERATIONS_WRITES_DOCUMENTSDB,
                METRIC_FILES_IMAGES_TRANSFORMED,
                // VectorsDB time series
                METRIC_DATABASES_VECTORSDB,
                METRIC_COLLECTIONS_VECTORSDB,
                METRIC_DOCUMENTS_VECTORSDB,
                METRIC_DATABASES_STORAGE_VECTORSDB,
                METRIC_DATABASES_OPERATIONS_READS_VECTORSDB,
                METRIC_DATABASES_OPERATIONS_WRITES_VECTORSDB,
                // Embeddings time series
                METRIC_EMBEDDINGS_TEXT,
                METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS,
                METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION,
                METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR
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

        $authorization->skip(function () use ($dbForProject, $dbForLogs, $firstDay, $lastDay, $period, $metrics, $limit, &$total, &$stats) {
            foreach ($metrics['total'] as $metric) {
                $db = ($metric === METRIC_FILES_IMAGES_TRANSFORMED) ? $dbForLogs : $dbForProject;

                $result = $db->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);
                $total[$metric] = $result['value'] ?? 0;
            }

            foreach ($metrics['period'] as $metric) {
                $db = ($metric === METRIC_FILES_IMAGES_TRANSFORMED) ? $dbForLogs : $dbForProject;

                $results = $db->find('stats', [
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
            $metric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS);
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
            $metric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS);
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
            $metric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS);
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
            $metric = str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_STORAGE);
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
            $metric = str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_STORAGE);

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
            $deploymentMetric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE);
            $deploymentValue = $dbForProject->findOne('stats', [
                Query::equal('metric', [$deploymentMetric]),
                Query::equal('period', ['inf'])
            ]);

            $buildMetric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE);
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
            $metric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS);
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
            $metric = str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS);
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

        // This total is includes free and paid SMS usage
        $authPhoneTotal = $authorization->skip(fn () => $dbForProject->sum('stats', 'value', [
            Query::equal('metric', [METRIC_AUTH_METHOD_PHONE]),
            Query::equal('period', ['1d']),
            Query::greaterThanEqual('time', $firstDay),
            Query::lessThan('time', $lastDay),
        ]));

        // This estimate is only for paid SMS usage
        $authPhoneMetrics = $authorization->skip(fn () => $dbForProject->find('stats', [
            Query::startsWith('metric', METRIC_AUTH_METHOD_PHONE . '.'),
            Query::equal('period', ['1d']),
            Query::greaterThanEqual('time', $firstDay),
            Query::lessThan('time', $lastDay),
        ]));

        $authPhoneEstimate = 0.0;
        $authPhoneCountryBreakdown = [];
        foreach ($authPhoneMetrics as $metric) {
            $parts = explode('.', $metric->getAttribute('metric'));
            $countryCode = $parts[3] ?? null;
            if ($countryCode === null) {
                continue;
            }

            $value = $metric->getAttribute('value', 0);

            if (isset($smsRates[$countryCode])) {
                $authPhoneEstimate += $value * $smsRates[$countryCode];
            }

            $authPhoneCountryBreakdown[] = [
                'name' => $countryCode,
                'value' => $value,
                'estimate' => isset($smsRates[$countryCode])
                    ? $value * $smsRates[$countryCode]
                    : 0.0,
            ];
        }

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
            'rowsTotal' => $total[METRIC_DOCUMENTS],
            'documentsdbDocumentsTotal' => $total[METRIC_DOCUMENTS_DOCUMENTSDB],
            'databasesTotal' => $total[METRIC_DATABASES],
            'documentsdbTotal' => $total[METRIC_DATABASES_DOCUMENTSDB],
            'databasesStorageTotal' => $total[METRIC_DATABASES_STORAGE],
            'documentsdbDatabasesStorageTotal' => $total[METRIC_DATABASES_STORAGE_DOCUMENTSDB],
            'usersTotal' => $total[METRIC_USERS],
            'bucketsTotal' => $total[METRIC_BUCKETS],
            'filesStorageTotal' => $total[METRIC_FILES_STORAGE],
            'functionsStorageTotal' => $total[METRIC_DEPLOYMENTS_STORAGE] + $total[METRIC_BUILDS_STORAGE],
            'buildsStorageTotal' => $total[METRIC_BUILDS_STORAGE],
            'deploymentsStorageTotal' => $total[METRIC_DEPLOYMENTS_STORAGE],
            'databasesReadsTotal' => $total[METRIC_DATABASES_OPERATIONS_READS],
            'databasesWritesTotal' => $total[METRIC_DATABASES_OPERATIONS_WRITES],
            'documentsdbDatabasesReadsTotal' => $total[METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB],
            'documentsdbDatabasesWritesTotal' => $total[METRIC_DATABASES_OPERATIONS_WRITES_DOCUMENTSDB],
            'vectorsdbDatabasesTotal' => $total[METRIC_DATABASES_VECTORSDB] ?? 0,
            'vectorsdbCollectionsTotal' => $total[METRIC_COLLECTIONS_VECTORSDB] ?? 0,
            'vectorsdbDocumentsTotal' => $total[METRIC_DOCUMENTS_VECTORSDB] ?? 0,
            'vectorsdbDatabasesStorageTotal' => $total[METRIC_DATABASES_STORAGE_VECTORSDB] ?? 0,
            'vectorsdbDatabasesReadsTotal' => $total[METRIC_DATABASES_OPERATIONS_READS_VECTORSDB] ?? 0,
            'vectorsdbDatabasesWritesTotal' => $total[METRIC_DATABASES_OPERATIONS_WRITES_VECTORSDB] ?? 0,
            'executionsBreakdown' => $executionsBreakdown,
            'bucketsBreakdown' => $bucketsBreakdown,
            'databasesReads' => $usage[METRIC_DATABASES_OPERATIONS_READS],
            'databasesWrites' => $usage[METRIC_DATABASES_OPERATIONS_WRITES],
            'documentsdbDatabasesReads' => $usage[METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB],
            'documentsdbDatabasesWrites' => $usage[METRIC_DATABASES_OPERATIONS_WRITES_DOCUMENTSDB],
            'documentsdbDatabasesStorage' => $usage[METRIC_DATABASES_STORAGE_DOCUMENTSDB],
            'vectorsdbDatabases' => $usage[METRIC_DATABASES_VECTORSDB] ?? [],
            'vectorsdbCollections' => $usage[METRIC_COLLECTIONS_VECTORSDB] ?? [],
            'vectorsdbDocuments' => $usage[METRIC_DOCUMENTS_VECTORSDB] ?? [],
            'vectorsdbDatabasesStorage' => $usage[METRIC_DATABASES_STORAGE_VECTORSDB] ?? [],
            'vectorsdbDatabasesReads' => $usage[METRIC_DATABASES_OPERATIONS_READS_VECTORSDB] ?? [],
            'vectorsdbDatabasesWrites' => $usage[METRIC_DATABASES_OPERATIONS_WRITES_VECTORSDB] ?? [],
            'databasesStorageBreakdown' => $databasesStorageBreakdown,
            'executionsMbSecondsBreakdown' => $executionsMbSecondsBreakdown,
            'buildsMbSecondsBreakdown' => $buildsMbSecondsBreakdown,
            'functionsStorageBreakdown' => $functionsStorageBreakdown,
            'authPhoneTotal' => $authPhoneTotal,
            'authPhoneEstimate' => $authPhoneEstimate,
            'authPhoneCountryBreakdown' => $authPhoneCountryBreakdown,
            'imageTransformations' => $usage[METRIC_FILES_IMAGES_TRANSFORMED],
            'imageTransformationsTotal' => $total[METRIC_FILES_IMAGES_TRANSFORMED],
            'embeddingsText' => $usage[METRIC_EMBEDDINGS_TEXT] ?? [],
            'embeddingsTextTokens' => $usage[METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS] ?? [],
            'embeddingsTextDuration' => $usage[METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION] ?? [],
            'embeddingsTextErrors' => $usage[METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR] ?? [],
            'embeddingsTextTotal' => $total[METRIC_EMBEDDINGS_TEXT] ?? 0,
            'embeddingsTextTokensTotal' => $total[METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS] ?? 0,
            'embeddingsTextDurationTotal' => $total[METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION] ?? 0,
            'embeddingsTextErrorsTotal' => $total[METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR] ?? 0,
        ]), Response::MODEL_USAGE_PROJECT);
    });
