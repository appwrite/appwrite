<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Explain;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explain\Get as DocumentExplain;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Get extends DocumentExplain
{
    public static function getName(): string
    {
        return 'explainRows';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_QUERY_PLAN;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows/explain')
            ->desc('Explain rows query plan')
            ->groups(['api', 'database'])
            ->label('scope', ['rows.read', 'documents.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/explain-rows.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    ),
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('tableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Table ID.', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. Same shape as listRows.', true)
            ->param('total', true, new Boolean(true), 'When true, the explain captures the COUNT(*) call listRows fires for the total field as a second entry. Mirrors listRows default behavior.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
