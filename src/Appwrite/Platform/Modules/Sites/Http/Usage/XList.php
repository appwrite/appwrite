<?php

namespace Appwrite\Platform\Modules\Sites\Http\Usage;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class XList extends Base
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
            ->label('scope', 'sites.read')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: null,
                name: 'listUsage',
                description: <<<EOT
                Get usage metrics and statistics for all sites in the project. View statistics including total deployments, builds, logs, storage usage, and compute time. The response includes both current totals and historical data for each metric. Use the optional range parameter to specify the time window for historical data: 24h (last 24 hours), 30d (last 30 days), or 90d (last 90 days). If not specified, defaults to 30 days.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_SITES,
                    )
                ]
            ))
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $range, Response $response, Database $dbForProject, Authorization $authorization)
    {
        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_SITES,
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_STORAGE),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_COMPUTE),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_EXECUTIONS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_MB_SECONDS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_SUCCESS),
            str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_FAILED),
            METRIC_SITES_REQUESTS,
            METRIC_SITES_INBOUND,
            METRIC_SITES_OUTBOUND,
        ];

        $authorization->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
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
            'executionsTotal' => $usage[$metrics[6]]['total'],
            'executionsTimeTotal' => $usage[$metrics[7]]['total'],
            'buildsMbSecondsTotal' => $usage[$metrics[8]]['total'],
            'executionsMbSecondsTotal' => $usage[$metrics[9]]['total'],
            'buildsSuccessTotal' => $usage[$metrics[10]]['total'],
            'buildsFailedTotal' => $usage[$metrics[11]]['total'],
            'requestsTotal' => $usage[$metrics[12]]['total'],
            'inboundTotal' => $usage[$metrics[13]]['total'],
            'outboundTotal' => $usage[$metrics[14]]['total'],
            'sites' => $usage[$metrics[0]]['data'],
            'deployments' => $usage[$metrics[1]]['data'],
            'deploymentsStorage' => $usage[$metrics[2]]['data'],
            'builds' => $usage[$metrics[3]]['data'],
            'buildsStorage' => $usage[$metrics[4]]['data'],
            'buildsTime' => $usage[$metrics[5]]['data'],
            'executions' => $usage[$metrics[6]]['data'],
            'executionsTime' => $usage[$metrics[7]]['data'],
            'buildsMbSeconds' => $usage[$metrics[8]]['data'],
            'executionsMbSeconds' => $usage[$metrics[9]]['data'],
            'buildsSuccess' => $usage[$metrics[10]]['data'],
            'buildsFailed' => $usage[$metrics[11]]['data'],
            'requests' => $usage[$metrics[12]]['data'],
            'inbound' => $usage[$metrics[13]]['data'],
            'outbound' => $usage[$metrics[14]]['data'],
        ]), Response::MODEL_USAGE_SITES);
    }
}
