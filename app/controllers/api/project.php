<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\WhiteList;

App::get('/v1/project/usage')
    ->desc('Get usage stats for a project')
    ->groups(['api', 'usage'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_PROJECT)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_NETWORK_REQUESTS,
            METRIC_NETWORK_INBOUND,
            METRIC_NETWORK_OUTBOUND,
            METRIC_EXECUTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES,
            METRIC_USERS,
            METRIC_BUCKETS,
            METRIC_FILES_STORAGE
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('period', [$period]),
                    Query::equal('metric', [$metric]),
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


        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

    foreach ($metrics as $metric) {
        $usage[$metric] = [];
        $leap = time() - ($days['limit'] * $days['factor']);
        while ($leap < time()) {
            $leap += $days['factor'];
            $formatDate = date($format, $leap);
            $usage[$metric][] = [
                'value' => $stats[$metric][$formatDate]['value'] ?? 0,
                'date' => $formatDate,
            ];
        }
    }


        $response->dynamic(new Document([
            'range' => $range,
            'requestsTotal' => ($usage[$metrics[0]]),
            'network' => ($usage[$metrics[1]] + $usage[$metrics[2]]),
            'executionsTotal' => $usage[$metrics[3]],
            'documentsTotal' => $usage[$metrics[4]],
            'databasesTotal' => $usage[$metrics[5]],
            'usersTotal' => $usage[$metrics[6]],
            'bucketsTotal' => $usage[$metrics[7]],
            'filesStorage' => $usage[$metrics[8]],
        ]), Response::MODEL_USAGE_PROJECT);
    });
