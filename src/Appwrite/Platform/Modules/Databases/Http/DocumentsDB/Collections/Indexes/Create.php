<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Create as IndexCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class Create extends IndexCreate
{
    public static function getName(): string
    {
        return 'createDocumentsDBIndex';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_INDEX;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/indexes')
            ->desc('Create index')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'index.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: 'documentsDB',
                group: $this->getSdkGroup(),
                name: 'createIndex',
                description: '/docs/references/documentsdb/create-index.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('key', null, fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Index Key.', false, ['dbForProject'])
            ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL]), 'Index type.')
            ->param('attributes', null, fn (Database $dbForProject) => new ArrayList(new Key(true, $dbForProject->getAdapter()->getMaxUIDLength()), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.', false, ['dbForProject'])
            ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
            ->param('lengths', [], new ArrayList(new Nullable(new Integer()), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Length of index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE, optional: true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
