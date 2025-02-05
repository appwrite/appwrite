<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Extend\Exception;
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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class GetSiteUsage extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getSiteUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/usage')
            ->desc('Get site usage')
            ->groups(['api', 'sites', 'usage'])
            ->label('scope', 'sites.read')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'getSiteUsage',
                description: '/docs/references/sites/get-site-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_SITE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $range, Response $response, Database $dbForProject)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{resourceType}', '{resourceInternalId}'], ['sites', $site->getInternalId()], METRIC_SITE_ID_DEPLOYMENTS),
            str_replace(['{resourceType}', '{resourceInternalId}'], ['sites', $site->getInternalId()], METRIC_SITE_ID_DEPLOYMENTS_STORAGE),
            str_replace('{siteInternalId}', $site->getInternalId(), METRIC_SITE_ID_BUILDS),
            str_replace('{siteInternalId}', $site->getInternalId(), METRIC_SITE_ID_BUILDS_STORAGE),
            str_replace('{siteInternalId}', $site->getInternalId(), METRIC_SITE_ID_BUILDS_COMPUTE),
            str_replace('{siteInternalId}', $site->getInternalId(), METRIC_SITE_ID_BUILDS_MB_SECONDS)
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
            'deploymentsTotal' => $usage[$metrics[0]]['total'],
            'deploymentsStorageTotal' => $usage[$metrics[1]]['total'],
            'buildsTotal' => $usage[$metrics[2]]['total'],
            'buildsStorageTotal' => $usage[$metrics[3]]['total'],
            'buildsTimeTotal' => $usage[$metrics[4]]['total'],
            'deployments' => $usage[$metrics[0]]['data'],
            'deploymentsStorage' => $usage[$metrics[1]]['data'],
            'builds' => $usage[$metrics[2]]['data'],
            'buildsStorage' => $usage[$metrics[3]]['data'],
            'buildsTime' => $usage[$metrics[4]]['data'],
            'buildsMbSecondsTotal' => $usage[$metrics[7]]['total'],
            'buildsMbSeconds' => $usage[$metrics[7]]['data']
            // TODO: Add more metrics for requests, bandwidth, etc.
        ]), Response::MODEL_USAGE_SITE);
    }
}
