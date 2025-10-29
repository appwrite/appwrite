<?php

namespace Appwrite\Platform\Modules\Functions\Http\Usage;

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

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/usage')
            ->desc('Get function usage')
            ->groups(['api', 'functions', 'usage'])
            ->label('scope', 'functions.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: null,
                name: 'getUsage',
                description: <<<EOT
                Get usage metrics and statistics for a for a specific function. View statistics including total deployments, builds, executions, storage usage, and compute time. The response includes both current totals and historical data for each metric. Use the optional range parameter to specify the time window for historical data: 24h (last 24 hours), 30d (last 30 days), or 90d (last 90 days). If not specified, defaults to 30 days.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_FUNCTION,
                    )
                ]
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $functionId, string $range, Response $response, Database $dbForProject, Authorization $authorization)
    {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_SUCCESS),
            str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_FAILED),
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

        $buildsTimeTotal = $usage[$metrics[4]]['total'] ?? 0;
        $buildsTotal = $usage[$metrics[2]]['total'] ?? 0;
        $response->dynamic(new Document([
            'range' => $range,
            'deploymentsTotal' => $usage[$metrics[0]]['total'],
            'deploymentsStorageTotal' => $usage[$metrics[1]]['total'],
            'buildsTotal' => $usage[$metrics[2]]['total'],
            'buildsSuccessTotal' => $usage[$metrics[9]]['total'],
            'buildsFailedTotal' => $usage[$metrics[10]]['total'],
            'buildsStorageTotal' => $usage[$metrics[3]]['total'],
            'buildsTimeTotal' => $usage[$metrics[4]]['total'],
            'buildsTimeAverage' => $buildsTotal === 0 ? 0 : (int) ($buildsTimeTotal / $buildsTotal),
            'executionsTotal' => $usage[$metrics[5]]['total'],
            'executionsTimeTotal' => $usage[$metrics[6]]['total'],
            'buildsMbSecondsTotal' => $usage[$metrics[7]]['total'],
            'executionsMbSecondsTotal' => $usage[$metrics[8]]['total'],
            'deployments' => $usage[$metrics[0]]['data'],
            'deploymentsStorage' => $usage[$metrics[1]]['data'],
            'builds' => $usage[$metrics[2]]['data'],
            'buildsStorage' => $usage[$metrics[3]]['data'],
            'buildsTime' => $usage[$metrics[4]]['data'],
            'executions' => $usage[$metrics[5]]['data'],
            'executionsTime' => $usage[$metrics[6]]['data'],
            'buildsMbSeconds' => $usage[$metrics[7]]['data'],
            'executionsMbSeconds' => $usage[$metrics[8]]['data'],
            'buildsSuccess' => $usage[$metrics[9]]['data'],
            'buildsFailed' => $usage[$metrics[10]]['data'],
        ]), Response::MODEL_USAGE_FUNCTION);
    }
}
