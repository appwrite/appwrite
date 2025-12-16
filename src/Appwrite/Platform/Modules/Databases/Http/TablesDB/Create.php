<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB;

use Appwrite\Platform\Modules\Databases\Http\Databases\Create as DatabaseCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends DatabaseCreate
{
    public static function getName(): string
    {
        return 'createTablesDatabase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/tablesdb')
            ->desc('Create database')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].create')
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'database.create')
            ->label('audits.resource', 'database/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'tablesDB',
                group: 'tablesdb',
                name: 'create',
                description: '/docs/references/tablesdb/create.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_DATABASE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
