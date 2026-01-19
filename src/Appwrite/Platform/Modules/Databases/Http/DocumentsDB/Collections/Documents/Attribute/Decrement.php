<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute\Decrement as DecrementDocumentAttribute;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Numeric;

class Decrement extends DecrementDocumentAttribute
{
    public static function getName(): string
    {
        return 'decrementDocumentsDBDocumentAttribute';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId/:attribute/decrement')
            ->desc('Decrement document attribute')
            ->groups(['api', 'database'])
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].documents.[documentId].update')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'documentsDB',
                group: $this->getSdkGroup(),
                name: 'decrementDocumentAttribute',
                description: '/docs/references/documentsdb/decrement-document-attribute.md',
                auth: [AuthType::SESSION, AuthType::JWT, AuthType::ADMIN, AuthType::KEY],
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
            ->param('attribute', '', new Key(), 'Attribute key.')
            ->param('value', 1, new Numeric(), 'Value to decrement the attribute by. The value must be a number.', true)
            ->param('min', null, new Numeric(), 'Minimum value for the attribute. If the current value is lesser than this value, an exception will be thrown.', true)
            ->param('transactionId', null, new UID(), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
