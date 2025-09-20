<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute\Increment as IncrementDocumentAttribute;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Numeric;

class Increment extends IncrementDocumentAttribute
{
    public static function getName(): string
    {
        return 'incrementDocumentAttribute';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId/attributes/:key/increment')
            ->desc('Increment document attribute')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
            ->label('scope', ['documents.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/documentsdb/increment-document-attribute.md',
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
            ->param('key', '', new Key(), 'Attribute key.')
            ->param('value', 1, new Numeric(), 'Value to increment the attribute by. The value must be a number.', true)
            ->param('max', null, new Numeric(), 'Maximum value for the attribute. If the current value is greater than this value, an error will be thrown.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }
}
