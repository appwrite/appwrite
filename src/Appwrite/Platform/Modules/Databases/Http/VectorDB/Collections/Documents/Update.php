<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Documents;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update as DocumentUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\JSON;

class Update extends DocumentUpdate
{
    public static function getName(): string
    {
        return 'updateVectorDBDocument';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/vectordb/:databaseId/collections/:collectionId/documents/:documentId')
            ->desc('Update document')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'document.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'vectorDB',
                group: $this->getSdkGroup(),
                name: 'updateDocument',
                description: '/docs/references/vectordb/update-document.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('data', [], new JSON(), 'Document data as JSON object. Include only fields and value pairs to be updated.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('transactionId', null, new UID(), 'Transaction ID for staging the operation.', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
