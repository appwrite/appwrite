<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createStringAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_STRING;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/string')
            ->desc('Create string attribute')
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
                description: '/docs/references/databases/create-string-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createStringColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->param('encrypt', false, new Boolean(), 'Toggle encryption for the attribute. Encryption enhances security by not storing any plain text values in the database. However, encrypted attributes cannot be queried.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string         $databaseId,
        string         $collectionId,
        string         $key,
        ?int           $size,
        ?bool          $required,
        ?string        $default,
        bool           $array,
        bool           $encrypt,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents,
        array $plan,
        Authorization $authorization
    ): void {
        if (!App::isDevelopment() && $encrypt && !empty($plan) && !($plan['databasesAllowEncrypt'] ?? false)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Encrypted string ' . $this->getSDKGroup() . ' are not available on your plan. Please upgrade to create encrypted string ' . $this->getSDKGroup() . '.');
        }

        if ($encrypt && $size < APP_DATABASE_ENCRYPT_SIZE_MIN) {
            throw new Exception(
                Exception::GENERAL_BAD_REQUEST,
                "Size too small. Encrypted strings require a minimum size of " . APP_DATABASE_ENCRYPT_SIZE_MIN . " characters."
            );
        }

        // Ensure default fits in the given size
        $validator = new Text($size, 0);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($this->getInvalidValueException(), $validator->getDescription());
        }

        $filters = [];
        if ($encrypt) {
            $filters[] = 'encrypt';
        }

        $attribute = $this->createAttribute(
            $databaseId,
            $collectionId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_STRING,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'array' => $array,
                'filters' => $filters,
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents,
            $authorization
        );

        $attribute->setAttribute('encrypt', $encrypt);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
