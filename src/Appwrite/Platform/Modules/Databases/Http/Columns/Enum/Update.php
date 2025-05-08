<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\Enum;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Attributes\Enum\Update as EnumUpdate;
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
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends EnumUpdate
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateEnumColumn';
    }

    public function __construct()
    {
        $this->setContext(DATABASE_COLUMNS_CONTEXT);
        $this->setResponseModel(UtopiaResponse::MODEL_COLUMN_ENUM);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/enum/:key')
            ->desc('Update enum column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
            ->label('audits.event', 'column.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-enum-attribute.md',
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
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('elements', null, new ArrayList(new Text(Database::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Updated list of enum values.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Nullable(new Text(0)), 'Default value for column when not provided. Cannot be set when column is required.')
            ->param('newKey', null, new Key(), 'New Column Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback(function (string $databaseId, string $tableId, string $key, array $elements, ?bool $required, ?string $default, ?string $newKey, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents) {
                parent::action($databaseId, $tableId, $key, $elements, $required, $default, $newKey, $response, $dbForProject, $queueForEvents);
            });
    }
}
