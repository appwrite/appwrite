<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Usage;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listDatabaseUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/usage')
            ->desc('Get databases usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: null,
                    name: 'listUsage',
                    description: '/docs/references/databases/list-usage.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_USAGE_DATABASES,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.listUsage'
                    )
                ),
            ])
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $range, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_DATABASES,
            METRIC_COLLECTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES_STORAGE,
            METRIC_DATABASES_OPERATIONS_READS,
            METRIC_DATABASES_OPERATIONS_WRITES,
        ];

        $authorization->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result = $dbForProject->findOne('stats', [
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
            'databasesTotal' => $usage[$metrics[0]]['total'],
            'collectionsTotal' => $usage[$metrics[1]]['total'],
            'tablesTotal' => $usage[$metrics[1]]['total'],
            'documentsTotal' => $usage[$metrics[2]]['total'],
            'rowsTotal' => $usage[$metrics[2]]['total'],
            'storageTotal' => $usage[$metrics[3]]['total'],
            'databasesReadsTotal' => $usage[$metrics[4]]['total'],
            'databasesWritesTotal' => $usage[$metrics[5]]['total'],
            'databases' => $usage[$metrics[0]]['data'],
            'collections' => $usage[$metrics[1]]['data'],
            'tables' => $usage[$metrics[1]]['data'],
            'documents' => $usage[$metrics[2]]['data'],
            'rows' => $usage[$metrics[2]]['data'],
            'storage' => $usage[$metrics[3]]['data'],
            'databasesReads' => $usage[$metrics[4]]['data'],
            'databasesWrites' => $usage[$metrics[5]]['data'],
        ]), UtopiaResponse::MODEL_USAGE_DATABASES);
    }
}
