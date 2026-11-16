<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\RoutePermissions;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Collection\Update as CollectionUpdate;
use Utopia\Database\Database;
use Utopia\Database\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId')
            ->desc('Update collection')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].update')
            ->label('audits.event', 'collection.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('usage.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-collection.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateTable',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Collection ID.', false, ['dbForProject'])
            ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.', true)
            ->param('permissions', null, new Nullable(new RoutePermissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->param('purge', false, new Boolean(true), 'When true, purge all cached list responses for this collection as part of the update. Use this to force readers to see fresh data immediately instead of waiting for the cache TTL to expire.', true)
            ->param('description', null, new Nullable(new Text(1024, 0)), 'Optional description for the collection.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, ?string $name, ?array $permissions, bool $documentSecurity, bool $enabled, bool $purge, ?string $description, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || $this->isDatabaseTypeMismatch($database)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$collectionId]);
        }

        if ($name) {
            $collection = $collection->setAttribute('name', $name);
        }

        if (!\is_null($description)) {
            $collection = $collection->setAttribute('description', $description);
        }

        $searchName = $name ?? $collection->getAttribute('name');

        // The verbatim list, not $permissions: the row stores the grants with every column
        // dropped, so inheriting from there would quietly widen read("any", "name") to
        // read("any") and replay that onto the table -- an update of `enabled` alone would
        // drop every column grant the table had.
        $permissions ??= $collection->getAttribute('columnPermissions') ?: $collection->getPermissions();

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        // Kept in the two forms the two stores want -- see Create.php for why they differ.
        $columnPermissions = Permission::hasColumns($permissions) ? $permissions : [];
        $rowPermissions = Permission::withoutColumns($permissions);

        $dbForDatabases = $getDatabasesDB($database, $collection);
        $update = fn () => $dbForDatabases->updateCollection(
            'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
            new CollectionUpdate(permissions: $permissions, documentSecurity: $documentSecurity),
        );

        // The collection first, the metadata row second. The collection is where the
        // permissions are judged -- a column that does not exist is refused there, before
        // anything is written -- and doing it first means a refusal leaves nothing behind.
        // Written the other way round, the row would already hold a grant the collection
        // never accepted: reported to the caller, and replayed by the next update.
        //
        // Scoped only when one of them names a column, so a route that does not offer the
        // feature keeps the library's refusal as its backstop.
        $columnPermissions === []
            ? $update()
            : $dbForDatabases->withColumnPermissions($update);

        $collection = $dbForProject->updateDocument(
            'database_' . $database->getSequence(),
            $collectionId,
            $collection
                ->setAttribute('$permissions', $rowPermissions)
                ->setAttribute('columnPermissions', $columnPermissions)
                ->setAttribute('documentSecurity', $documentSecurity)
                ->setAttribute('enabled', $enabled)
                ->setAttribute('search', \implode(' ', [$collectionId, $searchName, $collection->getAttribute('description', '')]))
        );

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam($this->getEventsParamKey(), $collection->getId());

        if ($purge) {
            $this->purgeListCache($dbForProject, $database, $collectionId);
        }

        $this->addRowBytesInfo($collection, $dbForProject);

        $response->dynamic($collection, $this->getResponseModel());
    }
}
