<?php

namespace Appwrite\Platform\Modules\Databases\Http\Tables\Logs;

use Appwrite\Platform\Modules\Databases\Http\Collections\Action;
use Appwrite\Platform\Modules\Databases\Http\Collections\Logs\XList as CollectionLogXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use MaxMind\Db\Reader;
use Utopia\Database\Database;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends CollectionLogXList
{
    use HTTP;

    public static function getName(): string
    {
        return 'listTableLogs';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_LOG_LIST;
    }

    public function __construct()
    {
        $this->setContext(DATABASE_TABLES_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/logs')
            ->desc('List table logs')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-collection-logs.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->callback(function (string $databaseId, string $tableId, array $queries, UtopiaResponse $response, Database $dbForProject, Locale $locale, Reader $geodb) {
                parent::action($databaseId, $tableId, $queries, $response, $dbForProject, $locale, $geodb);
            });
    }
}
