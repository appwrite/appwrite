<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Queries;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends XList
{
    public static function getName(): string
    {
        return 'createDocumentsQuery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/query')
            ->desc('Create query')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('usage.resource', 'database/{request.databaseId}/collection/{request.collectionId}/documents')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: 'createQuery',
                description: '/docs/references/databases/create-query.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createQuery',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, fn (Database $dbForProject) => new Nullable(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'Transaction ID to read uncommitted changes within the transaction.', true, ['dbForProject'])
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->param('ttl', 0, new Range(min: 0, max: 86400), 'TTL (seconds) for caching list responses. Responses are stored in an in-memory key-value cache, keyed per project, collection, schema version (attributes and indexes), caller authorization roles, and the exact query — so users with different permissions never share cached entries. Schema changes invalidate cached entries automatically; document writes do not, so choose a TTL you are comfortable serving as stale data. Set to 0 to disable caching. Must be between 0 and 86400 (24 hours).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
            ->inject('transactionState')
            ->inject('authorization')
            ->inject('utopia')
            ->callback($this->action(...));
    }
}
