<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateStringAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_STRING;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/string/:key')
            ->desc('Update string attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-string-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateStringColumn',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('key', '', fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Attribute Key.', false, ['dbForProject'])
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
            ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER), 'Maximum size of the string attribute.', true)
            ->param('newKey', null, fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'New Attribute Key.', true, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string         $databaseId,
        string         $collectionId,
        string         $key,
        ?bool          $required,
        ?string        $default,
        ?int           $size,
        ?string        $newKey,
        UtopiaResponse $response,
        Database       $dbForProject,
        Event          $queueForEvents
    ): void {
        $attribute = $this->updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            size: $size,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
