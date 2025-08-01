<?php

namespace Appwrite\Platform\Modules\Databases\Http\Grids\Tables\Columns\Relationship;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create as RelationshipCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Create extends RelationshipCreate
{
    public static function getName(): string
    {
        return 'createRelationshipColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_RELATIONSHIP;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/grids/tables/:tableId/columns/relationship')
            ->desc('Create relationship column')
            ->groups(['api', 'database'])
            ->label('scope', 'tables.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/grids/create-relationship-column.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('relatedTableId', '', new UID(), 'Related Table ID.')
            ->param('type', '', new WhiteList([
                Database::RELATION_ONE_TO_ONE,
                Database::RELATION_MANY_TO_ONE,
                Database::RELATION_MANY_TO_MANY,
                Database::RELATION_ONE_TO_MANY
            ], true), 'Relation type')
            ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
            ->param('key', null, new Key(), 'Column Key.', true)
            ->param('twoWayKey', null, new Key(), 'Two Way Column Key.', true)
            ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([
                Database::RELATION_MUTATE_CASCADE,
                Database::RELATION_MUTATE_RESTRICT,
                Database::RELATION_MUTATE_SET_NULL
            ], true), 'Constraints option', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
