<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Float;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createFloatAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_FLOAT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/float')
            ->desc('Create float attribute')
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
                description: '/docs/references/databases/create-float-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createFloatColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('min', null, new Nullable(new FloatValidator()), 'Minimum value.', true)
            ->param('max', null, new Nullable(new FloatValidator()), 'Maximum value.', true)
            ->param('default', null, new Nullable(new FloatValidator()), 'Default value. Cannot be set when required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $min ??= -PHP_FLOAT_MAX;
        $max ??= PHP_FLOAT_MAX;

        if ($min > $max) {
            throw new Exception($this->getInvalidValueException(), 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);
        if (!\is_null($default) && !$validator->isValid($default)) {
            throw new Exception($this->getInvalidValueException(), $validator->getDescription());
        }

        $attribute = $this->createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_FLOAT,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_FLOAT_RANGE,
            'formatOptions' => ['min' => $min, 'max' => $max],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents, $authorization);

        $formatOptions = $attribute->getAttribute('formatOptions', []);
        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
