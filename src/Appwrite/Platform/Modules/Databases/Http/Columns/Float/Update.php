<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\Float;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Columns\Action as ColumnAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Nullable;

class Update extends ColumnAction
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateFloatColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/float/:key')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/float/:key')
            ->desc('Update float column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
            ->label('audits.event', 'column.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'updateFloatColumn',
                description: '/docs/references/databases/update-float-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_ATTRIBUTE_FLOAT,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('min', null, new FloatValidator(), 'Minimum value', true)
            ->param('max', null, new FloatValidator(), 'Maximum value', true)
            ->param('default', null, new Nullable(new FloatValidator()), 'Default value. Cannot be set when required.')
            ->param('newKey', null, new Key(), 'New Column Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(
        string $databaseId,
        string $tableId,
        string $key,
        ?bool $required,
        ?float $min,
        ?float $max,
        ?float $default,
        ?string $newKey,
        UtopiaResponse $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $column = $this->updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_FLOAT,
            default: $default,
            required: $required,
            min: $min,
            max: $max,
            newKey: $newKey
        );

        $formatOptions = $column->getAttribute('formatOptions', []);
        if (!empty($formatOptions)) {
            $column->setAttribute('min', \floatval($formatOptions['min']));
            $column->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($column, UtopiaResponse::MODEL_ATTRIBUTE_FLOAT);
    }
}
