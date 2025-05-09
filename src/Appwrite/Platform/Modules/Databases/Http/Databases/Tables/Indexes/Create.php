<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Indexes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Create as IndexCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;

class Create extends IndexCreate
{
    use HTTP;

    public static function getName(): string
    {
        return 'createColumnIndex';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLUMN_INDEX;
    }

    public function __construct()
    {
        $this->setContext(DATABASE_COLUMN_INDEX_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes')
            ->desc('Create index')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'index.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: 'createIndex', // getName needs to be different from parent action to avoid conflict in path name
                description: '/docs/references/databases/create-index.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', null, new Key(), 'Index Key.')
            ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE]), 'Index type.')
            ->param('columns', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of columns to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' columns are allowed, each 32 characters long.')
            ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }
}
