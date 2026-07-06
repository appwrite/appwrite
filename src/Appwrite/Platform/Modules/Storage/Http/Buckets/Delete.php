<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Bus\Events\BucketDeleted;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteBucket';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/storage/buckets/:bucketId')
            ->desc('Delete bucket')
            ->groups(['api', 'storage'])
            ->label('scope', 'buckets.write')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('audits.event', 'bucket.delete')
            ->label('audits.resource', 'bucket/{request.bucketId}')
            ->label('usage.resource', 'bucket/{request.bucketId}')
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'buckets',
                name: 'deleteBucket',
                description: '/docs/references/storage/delete-bucket.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('bucketId', '', new UID(), 'Bucket unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('bus')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        Response $response,
        Database $dbForProject,
        DeletePublisher $publisherForDeletes,
        Bus $bus,
        Document $project,
        Document $actor
    ) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('buckets', $bucketId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove bucket from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $bucket,
        ));

        $bus->dispatch(new BucketDeleted($bucket, $project, $actor));

        $response->noContent();
    }
}
