<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\XList as AttributesXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Columns;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class XList extends AttributesXList
{
    public static function getName(): string
    {
        return 'listColumns';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns')
            ->desc('List columns')
            ->groups(['api', 'database'])
            ->label('scope', ['tables.read', 'collections.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/list-columns.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('tableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Table ID.', false, ['dbForProject'])
            ->param('queries', [], new Columns(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following columns: ' . implode(', ', Columns::ALLOWED_COLUMNS), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $tableId, array $queries, bool $includeTotal, UtopiaResponse $response, Database $dbForProject): void
    {
        // Call parent action with tableId as collectionId since they refer to the same resource
        parent::action($databaseId, $tableId, $queries, $includeTotal, $response, $dbForProject);
    }
}
