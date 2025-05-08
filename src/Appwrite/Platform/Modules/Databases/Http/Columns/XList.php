<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

use Appwrite\Platform\Modules\Databases\Http\Attributes\XList as AttributesXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Columns;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends AttributesXList
{
    use HTTP;

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
        $this->setContext(DATABASE_COLUMNS_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns')
            ->desc('List columns')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/list-attributes.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('queries', [], new Columns(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Columns::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback(function (string $databaseId, string $tableId, array $queries, UtopiaResponse $response, Database $dbForProject) {
                parent::action($databaseId, $tableId, $queries, $response, $dbForProject);
            });
    }
}
