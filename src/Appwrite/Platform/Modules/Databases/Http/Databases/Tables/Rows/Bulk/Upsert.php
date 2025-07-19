<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Rows\Bulk;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk\Upsert as DocumentsUpsert;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;

class Upsert extends DocumentsUpsert
{
    public static function getName(): string
    {
        return 'upsertRows';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows')
            ->desc('Create or update rows')
            ->groups(['api', 'database'])
            ->label('scope', 'rows.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSdkNamespace(),
                    group: $this->getSdkGroup(),
                    name: self::getName(),
                    description: '/docs/references/databases/upsert-rows.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('rows', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of row data as JSON objects. May contain partial rows.', false, ['plan'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->callback($this->action(...));
    }
}
