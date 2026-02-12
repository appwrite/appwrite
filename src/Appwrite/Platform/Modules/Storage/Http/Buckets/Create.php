<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createBucket';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/storage/buckets')
            ->desc('Create bucket')
            ->groups(['api', 'storage'])
            ->label('scope', 'buckets.write')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('event', 'buckets.[bucketId].create')
            ->label('audits.event', 'bucket.create')
            ->label('audits.resource', 'bucket/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'buckets',
                name: 'createBucket',
                description: '/docs/references/storage/create-bucket.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_BUCKET,
                    )
                ]
            ))
            ->param('bucketId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Bucket name')
            ->param('permissions', null, new Nullable(new \Utopia\Database\Validator\Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permission strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('fileSecurity', false, new Boolean(true), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(true), 'Is bucket enabled? When set to \'disabled\', users cannot access the files in this bucket but Server SDKs with and API key can still access the bucket. No files are lost when this is toggled.', true)
            ->param('maximumFileSize', fn (array $plan) => empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000, fn (array $plan) => new Range(1, empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human(System::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '.', true, ['plan'])
            ->param('allowedFileExtensions', [], new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
            ->param('compression', Compression::NONE, new WhiteList([Compression::NONE, Compression::GZIP, Compression::ZSTD], true), 'Compression algorithm chosen for compression. Can be one of ' . Compression::NONE . ',  [' . Compression::GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . Compression::ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
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
        int $maximumFileSize,
        array $allowedFileExtensions,
        ?string $compression,
        ?bool $encryption,
        bool $antivirus,
        bool $transformations,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $bucketId = $bucketId === 'unique()' ? ID::unique() : $bucketId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];
        $compression ??= Compression::NONE;
        $encryption ??= true;
        try {
            $files = (Config::getParam('collections', [])['buckets'] ?? [])['files'] ?? [];
            if (empty($files)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Files collection is not configured.');
            }

            $attributes = [];
            $indexes = [];

            foreach ($files['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($files['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => $index['$id'],
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            $dbForProject->createDocument('buckets', new Document([
                '$id' => $bucketId,
                '$collection' => 'buckets',
                '$permissions' => $permissions,
                'name' => $name,
                'maximumFileSize' => $maximumFileSize,
                'allowedFileExtensions' => $allowedFileExtensions,
                'fileSecurity' => $fileSecurity,
                'enabled' => $enabled,
                'compression' => $compression,
                'encryption' => $encryption,
                'antivirus' => $antivirus,
                'transformations' => $transformations,
                'search' => implode(' ', [$bucketId, $name]),
            ]));

            $bucket = $dbForProject->getDocument('buckets', $bucketId);

            $dbForProject->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes, permissions: $permissions, documentSecurity: $fileSecurity);
        } catch (DuplicateException) {
            throw new Exception(Exception::STORAGE_BUCKET_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($bucket, Response::MODEL_BUCKET);
    }
}
