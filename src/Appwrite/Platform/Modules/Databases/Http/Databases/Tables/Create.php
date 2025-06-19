<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables;

use Appwrite\Platform\Modules\Databases\Context;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Create as CollectionCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends CollectionCreate
{
    use HTTP;

    public static function getName(): string
    {
        return 'createTable';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TABLE;
    }

    public function __construct()
    {
        $this->setContext(Context::DATABASE_TABLES);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables')
            ->desc('Create table')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'table.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-table.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Table name. Max length: 128 chars.')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('rowSecurity', false, new Boolean(true), 'Enables configuring permissions for individual rows. A user needs one of row or table level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is table enabled? When set to \'disabled\', users cannot access the table but Server SDKs with and API key can still read and write to the table. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
