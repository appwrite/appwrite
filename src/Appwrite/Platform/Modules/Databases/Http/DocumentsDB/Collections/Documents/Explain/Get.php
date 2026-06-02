<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Explain;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explain\Get as DocumentExplain;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Get extends DocumentExplain
{
    public static function getName(): string
    {
        return 'explainDocumentsDBDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_QUERY_PLAN;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/documents/explain')
            ->desc('Explain documents query plan')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'documentsDB',
                group: $this->getSDKGroup(),
                name: 'explainDocuments',
                description: '/docs/references/documentsdb/explain-documents.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    ),
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. Same shape as listDocuments.', true)
            ->param('total', true, new Boolean(true), 'When true, the explain captures the COUNT(*) call listDocuments fires for the total field as a second entry. Mirrors listDocuments default behavior.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
