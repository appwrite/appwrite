<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Usage;

use Appwrite\Extend\Exception;
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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getDatabaseUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/usage')
            ->desc('Get database usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: null,
                    name: 'getUsage',
                    description: '/docs/references/databases/get-database-usage.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_USAGE_DATABASE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.getUsage'
                    )
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $range, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_COLLECTIONS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_DOCUMENTS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_STORAGE),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS),
            str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES)
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
            'collectionsTotal' => $usage[$metrics[0]]['total'],
            'tablesTotal' => $usage[$metrics[0]]['total'],
            'documentsTotal' => $usage[$metrics[1]]['total'],
            'rowsTotal' => $usage[$metrics[1]]['total'],
            'storageTotal' => $usage[$metrics[2]]['total'],
            'databaseReadsTotal' => $usage[$metrics[3]]['total'],
            'databaseWritesTotal' => $usage[$metrics[4]]['total'],
            'collections' => $usage[$metrics[0]]['data'],
            'tables' => $usage[$metrics[0]]['data'],
            'documents' => $usage[$metrics[1]]['data'],
            'rows' => $usage[$metrics[1]]['data'],
            'storage' => $usage[$metrics[2]]['data'],
            'databaseReads' => $usage[$metrics[3]]['data'],
            'databaseWrites' => $usage[$metrics[4]]['data'],
        ]), UtopiaResponse::MODEL_USAGE_DATABASE);
    }
}
