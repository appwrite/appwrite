<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Logs;

use Appwrite\Platform\Modules\Databases\Http\Databases\Logs\XList as DatabaseLogs;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends DatabaseLogs
{
    public static function getName(): string
    {
        return 'listDocumentsDBLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/documentsdb/:databaseId/logs')
            ->desc('List database logs')
            ->groups(['api', 'database'])
            ->label('scope', 'databases.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'documentsDB',
                    group: 'logs',
                    name: 'listDatabaseLogs',
                    description: '/docs/references/documentsdb/get-logs.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_LOG_LIST,
                        )
                    ],
                    contentType: ContentType::JSON
                ),
            ])
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->inject('authorization')
            ->inject('audit')
            ->callback($this->action(...));
    }
}
