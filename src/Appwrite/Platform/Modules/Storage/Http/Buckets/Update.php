<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Compression\Compression;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateBucket';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/storage/buckets/:bucketId')
            ->desc('Update bucket')
            ->groups(['api', 'storage'])
            ->label('scope', 'buckets.write')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('event', 'buckets.[bucketId].update')
            ->label('audits.event', 'bucket.update')
            ->label('audits.resource', 'bucket/{response.$id}')
            ->label('usage.resource', 'bucket/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'buckets',
                name: 'updateBucket',
                description: '/docs/references/storage/update-bucket.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_BUCKET,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Bucket unique ID.')
            ->param('name', null, new Text(128), 'Bucket name', false)
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('fileSecurity', null, new Nullable(new Boolean(true)), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', null, new Nullable(new Boolean(true)), 'Is bucket enabled? When set to \'disabled\', users cannot access the files in this bucket but Server SDKs with and API key can still access the bucket. No files are lost when this is toggled.', true)
            ->param('maximumFileSize', null, fn (array $plan) => new Nullable(new Range(1, empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000)), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human(System::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '.', true, ['plan'])
            ->param('allowedFileExtensions', null, new Nullable(new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
            ->param('compression', null, new Nullable(new WhiteList([Compression::NONE, Compression::GZIP, Compression::ZSTD], true)), 'Compression algorithm chosen for compression. Can be one of ' . Compression::NONE . ', [' . Compression::GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . Compression::ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true, enum: new Enum(name: 'Compression'))
            ->param('encryption', null, new Nullable(new Boolean(true)), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
            ->param('antivirus', null, new Nullable(new Boolean(true)), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
            ->param('transformations', null, new Nullable(new Boolean(true)), 'Are image transformations enabled?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $name,
        ?array $permissions,
        ?bool $fileSecurity,
        ?bool $enabled,
        ?int $maximumFileSize,
        ?array $allowedFileExtensions,
        ?string $compression,
        ?bool $encryption,
        ?bool $antivirus,
        ?bool $transformations,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        // Effective permissions / fileSecurity for the underlying collection update.
        // The document write below persists each setting only when the caller
        // actually sent it, so a name-only update can't reset omitted settings
        // (disable an enabled bucket, clear extensions, toggle features, etc.).
        $effectivePermissions = Permission::aggregate($permissions ?? $bucket->getPermissions());
        $effectiveFileSecurity = $fileSecurity ?? $bucket->getAttribute('fileSecurity', false);

        $updates = new Document([
            'name' => $name,
        ]);

        if ($permissions !== null) {
            $updates->setAttribute('$permissions', $effectivePermissions);
        }
        if ($fileSecurity !== null) {
            $updates->setAttribute('fileSecurity', $fileSecurity);
        }
        if ($enabled !== null) {
            $updates->setAttribute('enabled', $enabled);
        }
        if ($maximumFileSize !== null) {
            $updates->setAttribute('maximumFileSize', $maximumFileSize);
        }
        if ($allowedFileExtensions !== null) {
            $updates->setAttribute('allowedFileExtensions', $allowedFileExtensions);
        }
        if ($compression !== null) {
            $updates->setAttribute('compression', $compression);
        }
        if ($encryption !== null) {
            $updates->setAttribute('encryption', $encryption);
        }
        if ($antivirus !== null) {
            $updates->setAttribute('antivirus', $antivirus);
        }
        if ($transformations !== null) {
            $updates->setAttribute('transformations', $transformations);
        }

        $bucket = $dbForProject->updateDocument('buckets', $bucket->getId(), $updates);

        // Only re-sync the underlying collection when permissions or file
        // security actually changed; a name-only update leaves them untouched.
        if ($permissions !== null || $fileSecurity !== null) {
            $dbForProject->updateCollection('bucket_' . $bucket->getSequence(), $effectivePermissions, $effectiveFileSecurity);
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId());

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    }
}
