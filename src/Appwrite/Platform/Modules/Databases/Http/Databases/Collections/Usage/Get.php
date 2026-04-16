<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Usage;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Action;
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
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getCollectionUsage';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_USAGE_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/usage')
            ->desc('Get collection usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: null,
                name: self::getName(),
                description: '/docs/references/databases/get-collection-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.getTableUsage',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $range, string $collectionId, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);
        $collectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collectionDocument->getSequence());

        if ($collection->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$collectionId]);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collectionDocument->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS),
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

        $prefix = $this->isCollectionsAPI() ? 'documents' : 'rows';

        // prefix, prefixTotal
        $usageDocument = new Document([
            'range' => $range,
            $prefix => $usage[$metrics[0]]['data'],
            $prefix . 'Total' => $usage[$metrics[0]]['total'],
        ]);

        $response->dynamic($usageDocument, $this->getResponseModel());
    }
}
