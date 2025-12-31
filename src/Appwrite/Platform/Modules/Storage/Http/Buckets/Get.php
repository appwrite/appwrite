<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
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
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        Response $response,
        Database $dbForProject
    ) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    }
}
