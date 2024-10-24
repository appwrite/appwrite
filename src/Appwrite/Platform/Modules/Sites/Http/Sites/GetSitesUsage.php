<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class GetSitesUsage extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getSitesUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/usage')
            ->desc('Get sites usage')
            ->groups(['api', 'sites', 'usage'])
            ->label('scope', 'functions.read') // TODO: Update scope to sites.read
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'getUsage')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_USAGE_SITES)
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $range, Response $response, Database $dbForProject)
    {
        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_SITES,
            METRIC_DEPLOYMENTS,
            METRIC_DEPLOYMENTS_STORAGE,
            METRIC_BUILDS,
            METRIC_BUILDS_STORAGE,
            METRIC_BUILDS_COMPUTE,
            METRIC_BUILDS_MB_SECONDS,
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
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
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }
        $response->dynamic(new Document([
            'range' => $range,
            'sitesTotal' => $usage[$metrics[0]]['total'],
            'deploymentsTotal' => $usage[$metrics[1]]['total'],
            'deploymentsStorageTotal' => $usage[$metrics[2]]['total'],
            'buildsTotal' => $usage[$metrics[3]]['total'],
            'buildsStorageTotal' => $usage[$metrics[4]]['total'],
            'buildsTimeTotal' => $usage[$metrics[5]]['total'],
            'sites' => $usage[$metrics[0]]['data'],
            'deployments' => $usage[$metrics[1]]['data'],
            'deploymentsStorage' => $usage[$metrics[2]]['data'],
            'builds' => $usage[$metrics[3]]['data'],
            'buildsStorage' => $usage[$metrics[4]]['data'],
            'buildsTime' => $usage[$metrics[5]]['data'],
            'buildsMbSecondsTotal' => $usage[$metrics[8]]['total'],
            'buildsMbSeconds' => $usage[$metrics[8]]['data']
        ]), Response::MODEL_USAGE_SITES);
    }
}
