<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\WhiteList;

App::get('/v1/project/usage')
    ->desc('Get usage stats for a project')
    ->groups(['api'])
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
        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'project.$all.network.requests',
                'project.$all.network.bandwidth',
                'project.$all.storage.size',
                'users.$all.count.total',
                'databases.$all.count.total',
                'documents.$all.count.total',
                'executions.$all.compute.total',
                'buckets.$all.count.total'
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'requests' => $stats[$metrics[0]] ?? [],
                'network' => $stats[$metrics[1]] ?? [],
                'storage' => $stats[$metrics[2]] ?? [],
                'users' => $stats[$metrics[3]] ?? [],
                'databases' => $stats[$metrics[4]] ?? [],
                'documents' => $stats[$metrics[5]] ?? [],
                'executions' => $stats[$metrics[6]] ?? [],
                'buckets' => $stats[$metrics[7]] ?? [],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_PROJECT);
    });
