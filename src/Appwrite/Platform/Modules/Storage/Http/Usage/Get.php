<?php

namespace Appwrite\Platform\Modules\Storage\Http\Usage;

use Appwrite\Extend\Exception;
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

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getBucketUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/storage/:bucketId/usage')
            ->desc('Get bucket usage stats')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.read')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('sdk', new Method(
                namespace: 'storage',
                group: null,
                name: 'getBucketUsage',
                description: '/docs/references/storage/get-bucket-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_BUCKETS,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Bucket ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('getLogsDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $bucketId, string $range, Response $response, Document $project, Database $dbForProject, callable $getLogsDB, Authorization $authorization)
    {
        $dbForLogs = call_user_func($getLogsDB, $project);
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES),
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_STORAGE),
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED),
        ];

        $authorization->skip(function () use ($dbForProject, $dbForLogs, $bucket, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $db = ($metric === str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED))
                    ? $dbForLogs
                    : $dbForProject;

                $result = $db->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $db->find('stats', [
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
            'filesTotal' => $usage[$metrics[0]]['total'],
            'filesStorageTotal' => $usage[$metrics[1]]['total'],
            'files' => $usage[$metrics[0]]['data'],
            'storage' => $usage[$metrics[1]]['data'],
            'imageTransformations' => $usage[$metrics[2]]['data'],
            'imageTransformationsTotal' => $usage[$metrics[2]]['total'],
        ]), Response::MODEL_USAGE_BUCKETS);
    }
}
