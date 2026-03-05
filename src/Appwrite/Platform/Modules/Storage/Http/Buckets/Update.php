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
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
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
            ->param('fileSecurity', false, new Boolean(true), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(true), 'Is bucket enabled? When set to \'disabled\', users cannot access the files in this bucket but Server SDKs with and API key can still access the bucket. No files are lost when this is toggled.', true)
            ->param('maximumFileSize', fn (array $plan) => empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000, fn (array $plan) => new Range(1, empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human(System::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '.', true, ['plan'])
            ->param('allowedFileExtensions', [], new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
            ->param('compression', Compression::NONE, new WhiteList([Compression::NONE, Compression::GZIP, Compression::ZSTD], true), 'Compression algorithm chosen for compression. Can be one of ' . Compression::NONE . ', [' . Compression::GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . Compression::ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
            ->param('encryption', true, new Boolean(true), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
            ->param('antivirus', true, new Boolean(true), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
            ->param('transformations', true, new Boolean(true), 'Are image transformations enabled?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $name,
        ?array $permissions,
        bool $fileSecurity,
        bool $enabled,
        ?int $maximumFileSize,
        array $allowedFileExtensions,
        ?string $compression,
        ?bool $encryption,
        bool $antivirus,
        bool $transformations,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $permissions ??= $bucket->getPermissions();
        $maximumFileSize ??= $bucket->getAttribute('maximumFileSize', (int) System::getEnv('_APP_STORAGE_LIMIT', 0));
        $allowedFileExtensions ??= $bucket->getAttribute('allowedFileExtensions', []);
        $enabled ??= $bucket->getAttribute('enabled', true);
        $encryption ??= $bucket->getAttribute('encryption', true);
        $antivirus ??= $bucket->getAttribute('antivirus', true);
        $compression ??= $bucket->getAttribute('compression', Compression::NONE);
        $transformations ??= $bucket->getAttribute('transformations', true);

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        $bucket = $dbForProject->updateDocument('buckets', $bucket->getId(), $bucket
            ->setAttribute('name', $name)
            ->setAttribute('$permissions', $permissions)
            ->setAttribute('maximumFileSize', $maximumFileSize)
            ->setAttribute('allowedFileExtensions', $allowedFileExtensions)
            ->setAttribute('fileSecurity', $fileSecurity)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('encryption', $encryption)
            ->setAttribute('compression', $compression)
            ->setAttribute('antivirus', $antivirus)
            ->setAttribute('transformations', $transformations));

        $dbForProject->updateCollection('bucket_' . $bucket->getSequence(), $permissions, $fileSecurity);

        $queueForEvents
            ->setParam('bucketId', $bucket->getId());

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    }
}
