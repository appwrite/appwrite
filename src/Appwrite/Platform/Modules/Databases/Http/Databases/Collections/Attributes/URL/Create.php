<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\URL;

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
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\URL;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createUrlAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_URL;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/url')
            ->desc('Create URL attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-url-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'grids.createUrlColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new URL(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string         $databaseId,
        string         $collectionId,
        string         $key,
        ?bool          $required,
        ?string        $default,
        bool           $array,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents
    ): void {
        $attribute = $this->createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
