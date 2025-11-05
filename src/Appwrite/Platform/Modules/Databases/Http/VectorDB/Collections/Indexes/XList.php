<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Indexes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\XList as IndexXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Indexes;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class XList extends IndexXList
{
    public static function getName(): string
    {
        return 'listVectorDBIndexes';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_INDEX_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vectordb/:databaseId/collections/:collectionId/indexes')
            ->desc('List indexes')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'vectorDB',
                group: $this->getSdkGroup(),
                name: 'listIndexes', // getName needs to be different from parent action to avoid conflict in path name
                description: '/docs/references/vectordb/list-indexes.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('queries', [], new Indexes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Indexes::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
