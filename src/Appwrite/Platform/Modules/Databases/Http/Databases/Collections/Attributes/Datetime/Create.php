<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Datetime;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatetimeAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_DATETIME;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime')
            ->desc('Create datetime attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-datetime-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createDatetimeColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#createCollection).')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, fn (Database $dbForProject) => new Nullable(new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime())), 'Default value for the attribute in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when attribute is required.', true, ['dbForProject'])
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $attribute = $this->createAttribute(
            $databaseId,
            $collectionId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_DATETIME,
                'size' => 0,
                'required' => $required,
                'default' => $default,
                'array' => $array,
                'filters' => ['datetime'],
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents,
            $authorization
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
