<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Rows\Bulk;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk\Delete as DocumentsDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Delete extends DocumentsDelete
{
    public static function getName(): string
    {
        return 'deleteRows';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows')
            ->desc('Delete rows')
            ->groups(['api', 'database'])
            ->label('scope', 'rows.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'rows.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-rows.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/tables#tablesCreate).')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->callback($this->action(...));
    }
}
