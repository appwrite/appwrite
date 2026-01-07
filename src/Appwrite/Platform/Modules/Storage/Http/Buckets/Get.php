<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getBucket';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/storage/buckets/:bucketId')
            ->desc('Get bucket')
            ->groups(['api', 'storage'])
            ->label('scope', 'buckets.read')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'buckets',
                name: 'getBucket',
                description: '/docs/references/storage/get-bucket.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_BUCKET,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Bucket unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('getLogsDB')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        Response $response,
        Database $dbForProject,
        Document $project,
        callable $getLogsDB
    ): void {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $dbForLogs = call_user_func($getLogsDB, $project);
        $this->addBucketStorageSize($dbForLogs, $bucket);

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    }

    /**
     * Adds the latest aggregated bucket storage size from logs DB stats.
     */
    private function addBucketStorageSize(Database $dbForLogs, Document $bucket): void
    {
        $metric = str_replace(
            '{bucketInternalId}',
            $bucket->getSequence(),
            METRIC_BUCKET_ID_FILES_STORAGE
        );

        $statsDocId = md5('_inf_' . $metric);
        $storageStats = Authorization::skip(
            fn () => $dbForLogs->getDocument(
                'stats',
                $statsDocId,
                [Query::select(['value'])]
            )
        );

        /**
         * The value can be 0 if stats were not aggregated when this request was made!
         */
        $totalSize = $storageStats->isEmpty() ? 0 : $storageStats->getAttribute('value', 0);

        $bucket->setAttribute('totalSize', $totalSize);
    }
}
