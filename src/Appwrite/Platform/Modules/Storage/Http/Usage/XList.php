<?php

namespace Appwrite\Platform\Modules\Storage\Http\Usage;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Async\Promise;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class XList extends Action
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
            ->setHttpPath('/v1/storage/usage')
            ->desc('Get storage usage stats')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.read')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('sdk', new Method(
                namespace: 'storage',
                group: null,
                name: 'getUsage',
                description: '/docs/references/storage/get-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_STORAGE,
                    )
                ]
            ))
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
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
            METRIC_BUCKETS,
            METRIC_FILES,
            METRIC_FILES_STORAGE,
        ];

        $authorization->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            $limit = $days['limit'];
            $period = $days['period'];

            $tasks = [];
            foreach ($metrics as $metric) {
                $tasks[$metric . '_total'] = fn () => $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);
                $tasks[$metric . '_data'] = fn () => $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
            }

            $results = Promise::map($tasks)->await();

            foreach ($metrics as $metric) {
                $stats[$metric]['total'] = $results[$metric . '_total']['value'] ?? 0;
                $stats[$metric]['data'] = [];
                foreach ($results[$metric . '_data'] as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\\TH:00:00.000P',
            '1d' => 'Y-m-d\\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
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
            'bucketsTotal' => $usage[$metrics[0]]['total'],
            'filesTotal' => $usage[$metrics[1]]['total'],
            'filesStorageTotal' => $usage[$metrics[2]]['total'],
            'buckets' => $usage[$metrics[0]]['data'],
            'files' => $usage[$metrics[1]]['data'],
            'storage' => $usage[$metrics[2]]['data'],
        ]), Response::MODEL_USAGE_STORAGE);
    }
}
