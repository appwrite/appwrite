<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\ClamAV\Network;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Buckets;
use Appwrite\Utopia\Database\Validator\Queries\Files;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/storage/buckets')
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
    ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permission strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('fileSecurity', false, new Boolean(true), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(true), 'Is bucket enabled? When set to \'disabled\', users cannot access the files in this bucket but Server SDKs with and API key can still access the bucket. No files are lost when this is toggled.', true)
    ->param('maximumFileSize', fn (array $plan) => empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000, fn (array $plan) => new Range(1, empty($plan['fileSize']) ? (int) System::getEnv('_APP_STORAGE_LIMIT', 0) : $plan['fileSize'] * 1000 * 1000), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human(System::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '.', true, ['plan'])
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
    ->param('compression', Compression::NONE, new WhiteList([Compression::NONE, Compression::GZIP, Compression::ZSTD], true), 'Compression algorithm choosen for compression. Can be one of ' . Compression::NONE . ',  [' . Compression::GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . Compression::ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
    ->param('encryption', true, new Boolean(true), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antivirus', true, new Boolean(true), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->param('transformations', true, new Boolean(true), 'Are image transformations enabled?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $bucketId, string $name, ?array $permissions, bool $fileSecurity, bool $enabled, int $maximumFileSize, array $allowedFileExtensions, ?string $compression, ?bool $encryption, bool $antivirus, bool $transformations, Response $response, Database $dbForProject, Event $queueForEvents) {

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
            ->setParam('bucketId', $bucket->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::get('/v1/storage/buckets')
    ->desc('List buckets')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'buckets',
        name: 'listBuckets',
        description: '/docs/references/storage/list-buckets.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_BUCKET_LIST,
            )
        ]
    ))
    ->param('queries', [], new Buckets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Buckets::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $bucketId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('buckets', $bucketId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Bucket '{$bucketId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $buckets = $dbForProject->find('buckets', $queries);
            $total = $includeTotal ? $dbForProject->count('buckets', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }
        $response->dynamic(new Document([
            'buckets' => $buckets,
            'total' => $total,
        ]), Response::MODEL_BUCKET_LIST);
    });

App::get('/v1/storage/buckets/:bucketId')
    ->desc('Get bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'buckets',
        name: 'getBucket',
        description: '/docs/references/storage/get-bucket.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_BUCKET,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $bucketId, Response $response, Database $dbForProject) {

        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::put('/v1/storage/buckets/:bucketId')
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
    ->param('compression', Compression::NONE, new WhiteList([Compression::NONE, Compression::GZIP, Compression::ZSTD], true), 'Compression algorithm choosen for compression. Can be one of ' . Compression::NONE . ', [' . Compression::GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . Compression::ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
    ->param('encryption', true, new Boolean(true), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antivirus', true, new Boolean(true), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->param('transformations', true, new Boolean(true), 'Are image transformations enabled?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $bucketId, string $name, ?array $permissions, bool $fileSecurity, bool $enabled, ?int $maximumFileSize, array $allowedFileExtensions, ?string $compression, ?bool $encryption, bool $antivirus, bool $transformations, Response $response, Database $dbForProject, Event $queueForEvents) {
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
    });

App::delete('/v1/storage/buckets/:bucketId')
    ->desc('Delete bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('audits.event', 'bucket.delete')
    ->label('event', 'buckets.[bucketId].delete')
    ->label('audits.resource', 'bucket/{request.bucketId}')
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'buckets',
        name: 'deleteBucket',
        description: '/docs/references/storage/delete-bucket.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->action(function (string $bucketId, Response $response, Database $dbForProject, Delete $queueForDeletes, Event $queueForEvents) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('buckets', $bucketId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove bucket from DB');
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($bucket);

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setPayload($response->output($bucket, Response::MODEL_BUCKET))
        ;

        $response->noContent();
    });

App::post('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files')
    ->desc('Create file')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('audits.event', 'file.create')
    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->label('audits.resource', 'file/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId},chunkId:{chunkId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'createFile',
        description: '/docs/references/storage/create-file.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_FILE,
            )
        ],
        type: MethodType::UPLOAD,
        requestType: ContentType::MULTIPART
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('file', [], new File(), 'Binary file. Appwrite SDKs provide helpers to handle file input. [Learn about file input](https://appwrite.io/docs/products/storage/upload-download#input-file).', skipValidation: true)
    ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permission strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('mode')
    ->inject('deviceForFiles')
    ->inject('deviceForLocal')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, mixed $file, ?array $permissions, Request $request, Response $response, Database $dbForProject, Document $user, Event $queueForEvents, string $mode, Device $deviceForFiles, Device $deviceForLocal, Authorization $authorization) {

        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$authorization->isValid(new Input(Database::PERMISSION_CREATE, $bucket->getCreate()))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ];

        // Map aggregate permissions to into the set of individual permissions they represent.
        $permissions = Permission::aggregate($permissions, $allowedPermissions);

        // Add permissions for current the user if none were provided.
        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($user->getId())) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                }
            }
        }

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!User::isApp($roles) && !User::isPrivileged($roles)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!$authorization->hasRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        $maximumFileSize = $bucket->getAttribute('maximumFileSize', 0);
        if ($maximumFileSize > (int) System::getEnv('_APP_STORAGE_LIMIT', 0)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Maximum bucket file size is larger than _APP_STORAGE_LIMIT');
        }


        $file = $request->getFiles('file');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY);
        }

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        $contentRange = $request->getHeader('content-range');
        $fileId = $fileId === 'unique()' ? ID::unique() : $fileId;
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $fileId = $request->getHeader('x-appwrite-id', $fileId);
            // TODO make `end >= $fileSize` in next breaking version
            if (is_null($start) || is_null($end) || is_null($fileSize) || $end > $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            $idValidator = new UID();
            if (!$idValidator->isValid($fileId)) {
                throw new Exception(Exception::STORAGE_INVALID_APPWRITE_ID);
            }

            // TODO remove the condition that checks `$end === $fileSize` in next breaking version
            if ($end === $fileSize - 1 || $end === $fileSize) {
                //if it's a last chunks the chunk size might differ, so we set the $chunks and $chunk to -1 notify it's last chunk
                $chunks = $chunk = -1;
            } else {
                // Calculate total number of chunks based on the chunk size i.e ($rangeEnd - $rangeStart)
                $chunks = (int) ceil($fileSize / ($end + 1 - $start));
                $chunk = (int) ($start / ($end + 1 - $start)) + 1;
            }
        }

        /**
         * Validators
         */
        // Check if file type is allowed
        $allowedFileExtensions = $bucket->getAttribute('allowedFileExtensions', []);
        $fileExt = new FileExt($allowedFileExtensions);
        if (!empty($allowedFileExtensions) && !$fileExt->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
        }

        // Check if file size is exceeding allowed limit
        $fileSizeValidator = new FileSize($maximumFileSize);
        if (!$fileSizeValidator->isValid($fileSize)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE, 'File size not allowed');
        }

        $upload = new Upload();
        if (!$upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        // Save to storage
        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $path = $deviceForFiles->getPath($fileId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $path = str_ireplace($deviceForFiles->getRoot(), $deviceForFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root

        $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        if (!$file->isEmpty()) {
            $chunks = $file->getAttribute('chunksTotal', 1);
            $uploaded = $file->getAttribute('chunksUploaded', 0);
            $metadata = $file->getAttribute('metadata', []);

            if ($chunk === -1) {
                $chunk = $chunks;
            }

            if ($uploaded === $chunks) {
                throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
            }
        }

        $chunksUploaded = $deviceForFiles->upload($fileTmpName, $path, $chunk, $chunks, $metadata);

        if (empty($chunksUploaded)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed uploading file');
        }

        if ($chunksUploaded === $chunks) {
            if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled' && $bucket->getAttribute('antivirus', true) && $fileSize <= APP_LIMIT_ANTIVIRUS && $deviceForFiles->getType() === Storage::DEVICE_LOCAL) {
                $antivirus = new Network(
                    System::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                    (int) System::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
                );

                if (!$antivirus->fileScan($path)) {
                    $deviceForFiles->delete($path);
                    throw new Exception(Exception::STORAGE_INVALID_FILE);
                }
            }

            $mimeType = $deviceForFiles->getFileMimeType($path); // Get mime-type before compression and encryption
            $fileHash = $deviceForFiles->getFileHash($path); // Get file hash before compression and encryption
            $data = '';
            // Compression
            $algorithm = $bucket->getAttribute('compression', Compression::NONE);
            if ($fileSize <= APP_STORAGE_READ_BUFFER && $algorithm != Compression::NONE) {
                $data = $deviceForFiles->read($path);
                switch ($algorithm) {
                    case Compression::ZSTD:
                        $compressor = new Zstd();
                        break;
                    case Compression::GZIP:
                    default:
                        $compressor = new GZIP();
                        break;
                }
                $data = $compressor->compress($data);
            } else {
                // reset the algorithm to none as we do not compress the file
                // if file size exceedes the APP_STORAGE_READ_BUFFER
                // regardless the bucket compression algoorithm
                $algorithm = Compression::NONE;
            }

            if ($bucket->getAttribute('encryption', true) && $fileSize <= APP_STORAGE_READ_BUFFER) {
                if (empty($data)) {
                    $data = $deviceForFiles->read($path);
                }
                $key = System::getEnv('_APP_OPENSSL_KEY_V1');
                $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
                $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);
            }

            if (!empty($data)) {
                if (!$deviceForFiles->write($path, $data, $mimeType)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to save file');
                }
            }

            $sizeActual = $deviceForFiles->getFileSize($path);

            $openSSLVersion = null;
            $openSSLCipher = null;
            $openSSLTag = null;
            $openSSLIV = null;

            if ($bucket->getAttribute('encryption', true) && $fileSize <= APP_STORAGE_READ_BUFFER) {
                $openSSLVersion = '1';
                $openSSLCipher = OpenSSL::CIPHER_AES_128_GCM;
                $openSSLTag = \bin2hex($tag);
                $openSSLIV = \bin2hex($iv);
            }

            if ($file->isEmpty()) {
                $doc = new Document([
                    '$id' => $fileId,
                    '$permissions' => $permissions,
                    'bucketId' => $bucket->getId(),
                    'bucketInternalId' => $bucket->getSequence(),
                    'name' => $fileName,
                    'path' => $path,
                    'signature' => $fileHash,
                    'mimeType' => $mimeType,
                    'sizeOriginal' => $fileSize,
                    'sizeActual' => $sizeActual,
                    'algorithm' => $algorithm,
                    'comment' => '',
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                    'openSSLVersion' => $openSSLVersion,
                    'openSSLCipher' => $openSSLCipher,
                    'openSSLTag' => $openSSLTag,
                    'openSSLIV' => $openSSLIV,
                    'search' => implode(' ', [$fileId, $fileName]),
                    'metadata' => $metadata,
                ]);

                try {
                    $file = $dbForProject->createDocument('bucket_' . $bucket->getSequence(), $doc);
                } catch (DuplicateException) {
                    throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                } catch (NotFoundException) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }
            } else {
                $file = $file
                    ->setAttribute('$permissions', $permissions)
                    ->setAttribute('signature', $fileHash)
                    ->setAttribute('mimeType', $mimeType)
                    ->setAttribute('sizeActual', $sizeActual)
                    ->setAttribute('algorithm', $algorithm)
                    ->setAttribute('openSSLVersion', $openSSLVersion)
                    ->setAttribute('openSSLCipher', $openSSLCipher)
                    ->setAttribute('openSSLTag', $openSSLTag)
                    ->setAttribute('openSSLIV', $openSSLIV)
                    ->setAttribute('metadata', $metadata)
                    ->setAttribute('chunksUploaded', $chunksUploaded);

                /**
                 * Validate create permission and skip authorization in updateDocument
                 * Without this, the file creation will fail when user doesn't have update permission
                 * However as with chunk upload even if we are updating, we are essentially creating a file
                 * adding it's new chunk so we validate create permission instead of update
                 */
                if (!$authorization->isValid(new Input(Database::PERMISSION_CREATE, $bucket->getCreate()))) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
                }
                $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file));
            }
        } else {
            if ($file->isEmpty()) {
                $doc = new Document([
                    '$id' => ID::custom($fileId),
                    '$permissions' => $permissions,
                    'bucketId' => $bucket->getId(),
                    'bucketInternalId' => $bucket->getSequence(),
                    'name' => $fileName,
                    'path' => $path,
                    'signature' => '',
                    'mimeType' => '',
                    'sizeOriginal' => $fileSize,
                    'sizeActual' => 0,
                    'algorithm' => '',
                    'comment' => '',
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                    'search' => implode(' ', [$fileId, $fileName]),
                    'metadata' => $metadata,
                ]);

                try {
                    $file = $dbForProject->createDocument('bucket_' . $bucket->getSequence(), $doc);
                } catch (DuplicateException) {
                    throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                } catch (NotFoundException) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }
            } else {
                $file = $file
                    ->setAttribute('chunksUploaded', $chunksUploaded)
                    ->setAttribute('metadata', $metadata);

                /**
                 * Validate create permission and skip authorization in updateDocument
                 * Without this, the file creation will fail when user doesn't have update permission
                 * However as with chunk upload even if we are updating, we are essentially creating a file
                 * adding it's new chunk so we validate create permission instead of update
                 */
                if (!$authorization->isValid(new Input(Database::PERMISSION_CREATE, $bucket->getCreate()))) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
                }

                try {
                    $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file));
                } catch (NotFoundException) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }
            }
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket);

        $metadata = null; // was causing leaks as it was passed by reference

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files')
    ->desc('List files')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'listFiles',
        description: '/docs/references/storage/list-files.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_FILE_LIST,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('queries', [], new Files(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Files::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('mode')
    ->action(function (string $bucketId, array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject, Authorization $authorization, string $mode) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $fileId = $cursor->getValue();

            if ($fileSecurity && !$valid) {
                $cursorDocument = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
            } else {
                $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
            }

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "File '{$fileId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            if ($fileSecurity && !$valid) {
                $files = $dbForProject->find('bucket_' . $bucket->getSequence(), $queries);
                $total = $includeTotal ? $dbForProject->count('bucket_' . $bucket->getSequence(), $queries, APP_LIMIT_COUNT) : 0;
            } else {
                $files = $authorization->skip(fn () => $dbForProject->find('bucket_' . $bucket->getSequence(), $queries));
                $total = $includeTotal ? $authorization->skip(fn () => $dbForProject->count('bucket_' . $bucket->getSequence(), $queries, APP_LIMIT_COUNT)) : 0;
            }
        } catch (NotFoundException) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $response->dynamic(new Document([
            'files' => $files,
            'total' => $total,
        ]), Response::MODEL_FILE_LIST);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId')
    ->desc('Get file')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'getFile',
        description: '/docs/references/storage/get-file.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_FILE,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('mode')
    ->action(function (string $bucketId, string $fileId, Response $response, Database $dbForProject, Authorization $authorization, string $mode) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $response->dynamic($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/preview')
    ->alias('/v1/storage/files/:fileId/preview')
    ->desc('Get file preview')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('cache', true)
    ->label('cache.resourceType', 'bucket/{request.bucketId}')
    ->label('cache.resource', 'file/{request.fileId}')
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'getFilePreview',
        description: '/docs/references/storage/get-file-preview.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE
            )
        ],
        type: MethodType::LOCATION,
        contentType: ContentType::IMAGE
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID')
    ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
    ->param('gravity', Image::GRAVITY_CENTER, new WhiteList(Image::getGravityTypes()), 'Image crop gravity. Can be one of ' . implode(",", Image::getGravityTypes()), true)
    ->param('quality', -1, new Range(-1, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
    ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
    ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
    ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
    ->param('opacity', 1, new Range(0, 1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
    ->param('rotation', 0, new Range(-360, 360), 'Preview image rotation in degrees. Pass an integer between -360 and 360.', true)
    ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
    ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
    // NOTE: this is only for the sdk generator and is not used in the action below and is utilised in `resources.php` for `resourceToken`.
    ->param('token', '', new Text(512), 'File token for accessing this file.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('resourceToken')
    ->inject('deviceForFiles')
    ->inject('deviceForLocal')
    ->inject('project')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, int $width, int $height, string $gravity, int $quality, int $borderWidth, string $borderColor, int $borderRadius, float $opacity, int $rotation, string $background, string $output, ?string $token, Request $request, Response $response, Database $dbForProject, Document $resourceToken, Device $deviceForFiles, Device $deviceForLocal, Document $project, Authorization $authorization) {

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        /* @type Document $bucket */
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$bucket->getAttribute('transformations', true) && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::STORAGE_BUCKET_TRANSFORMATIONS_DISABLED);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            /* @type Document $file */
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $inputs = Config::getParam('storage-inputs');
        $outputs = Config::getParam('storage-outputs');
        $fileLogos = Config::getParam('storage-logos');

        $path = $file->getAttribute('path');
        $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
        $algorithm = $file->getAttribute('algorithm', Compression::NONE);
        $cipher = $file->getAttribute('openSSLCipher');
        $mime = $file->getAttribute('mimeType');
        if (!\in_array($mime, $inputs) || $file->getAttribute('sizeActual') > (int) System::getEnv('_APP_STORAGE_PREVIEW_LIMIT', APP_STORAGE_READ_BUFFER)) {
            if (!\in_array($mime, $inputs)) {
                $path = (\array_key_exists($mime, $fileLogos)) ? $fileLogos[$mime] : $fileLogos['default'];
            } else {
                // it was an image but the file size exceeded the limit
                $path = $fileLogos['default_image'];
            }

            $algorithm = Compression::NONE;
            $cipher = null;
            $background = (empty($background)) ? 'eceff1' : $background;
            $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
            $deviceForFiles = $deviceForLocal;
        }

        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        if (empty($output)) {
            // when file extension is provided but it's not one of our
            // supported outputs we fallback to `jpg`
            if (!empty($type) && !array_key_exists($type, $outputs)) {
                $type = 'jpg';
            }

            // when file extension is not provided and the mime type is not one of our supported outputs
            // we fallback to `jpg` output format
            $output = empty($type) ? (array_search($mime, $outputs) ?? 'jpg') : $type;
        }

        $startTime = \microtime(true);

        $source = $deviceForFiles->read($path);

        $downloadTime = \microtime(true) - $startTime;

        if (!empty($cipher)) { // Decrypt
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        $decryptionTime = \microtime(true) - $startTime - $downloadTime;

        switch ($algorithm) {
            case Compression::ZSTD:
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case Compression::GZIP:
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        $decompressionTime = \microtime(true) - $startTime - $downloadTime - $decryptionTime;

        try {
            $image = new Image($source);
        } catch (ImagickException $e) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $e->getMessage());
        }

        $image->crop((int) $width, (int) $height, $gravity);

        if (!empty($opacity) || $opacity === 0) {
            $image->setOpacity($opacity);
        }

        if (!empty($background)) {
            $image->setBackground('#' . $background);
        }

        if (!empty($borderWidth)) {
            $image->setBorder($borderWidth, '#' . $borderColor);
        }

        if (!empty($borderRadius)) {
            $image->setBorderRadius($borderRadius);
        }

        if (!empty($rotation)) {
            $image->setRotation(($rotation + 360) % 360);
        }

        $data = $image->output($output, $quality);

        $renderingTime = \microtime(true) - $startTime - $downloadTime - $decryptionTime - $decompressionTime;

        $totalTime = \microtime(true) - $startTime;

        Console::info("File preview rendered,project=" . $project->getId() . ",bucket=" . $bucketId . ",file=" . $file->getId() . ",uri=" . $request->getURI() . ",total=" . $totalTime . ",rendering=" . $renderingTime . ",decryption=" . $decryptionTime . ",decompression=" . $decompressionTime . ",download=" . $downloadTime);

        $contentType = (\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'];

        //Do not update transformedAt if it's a console user
        if (!User::isPrivileged($authorization->getRoles())) {
            $transformedAt = $file->getAttribute('transformedAt', '');
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $transformedAt) {
                $file->setAttribute('transformedAt', DateTime::now());
                $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $file->getAttribute('bucketInternalId'), $file->getId(), $file));
            }
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType($contentType)
            ->file($data);

        unset($image);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/download')
    ->alias('/v1/storage/files/:fileId/download')
    ->desc('Get file for download')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'getFileDownload',
        description: '/docs/references/storage/get-file-download.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE
            )
        ],
        type: MethodType::LOCATION,
        contentType: ContentType::ANY,
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    // NOTE: this is only for the sdk generator and is not used in the action below and is utilised in `resources.php` for `resourceToken`.
    ->param('token', '', new Text(512), 'File token for accessing this file.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('mode')
    ->inject('resourceToken')
    ->inject('deviceForFiles')
    ->action(function (string $bucketId, string $fileId, ?string $token, Request $request, Response $response, Database $dbForProject, Authorization $authorization, string $mode, Document $resourceToken, Device $deviceForFiles) {
        /* @type Document $bucket */
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            /* @type Document $file */
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $path = $file->getAttribute('path', '');

        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null || $end - $start > APP_STORAGE_READ_BUFFER) {
                $end = min(($start + MAX_OUTPUT_CHUNK_SIZE - 1), ($size - 1));
            }

            if ($unit !== 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);
        }

        $response
            ->setContentType($file->getAttribute('mimeType'))
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $file->getAttribute('name', '') . '"')
        ;

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $deviceForFiles->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($file->getAttribute('algorithm', Compression::NONE)) {
            case Compression::ZSTD:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case Compression::GZIP:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        if (!empty($source)) {
            if (!empty($rangeHeader)) {
                $response->send(substr($source, $start, ($end - $start + 1)));
                return;
            }
            $response->send($source);
            return;
        }

        if (!empty($rangeHeader)) {
            $response->send($deviceForFiles->read($path, $start, ($end - $start + 1)));
            return;
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForFiles->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceForFiles->read($path));
        }
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/view')
    ->alias('/v1/storage/files/:fileId/view')
    ->desc('Get file for view')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'getFileView',
        description: '/docs/references/storage/get-file-view.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        type: MethodType::LOCATION,
        contentType: ContentType::ANY,
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    // NOTE: this is only for the sdk generator and is not used in the action below and is utilised in `resources.php` for `resourceToken`.
    ->param('token', '', new Text(512), 'File token for accessing this file.', true)
    ->inject('response')
    ->inject('request')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('resourceToken')
    ->inject('deviceForFiles')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, ?string $token, Response $response, Request $request, Database $dbForProject, string $mode, Document $resourceToken, Device $deviceForFiles, Authorization $authorization) {
        /* @type Document $bucket */
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            /* @type Document $file */
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $mimes = Config::getParam('storage-mimes');

        $path = $file->getAttribute('path', '');

        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        $contentType = 'text/plain';

        if (\in_array($file->getAttribute('mimeType'), $mimes)) {
            $contentType = $file->getAttribute('mimeType');
        }

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null || $end - $start > APP_STORAGE_READ_BUFFER) {
                $end = min(($start + APP_STORAGE_READ_BUFFER - 1), ($size - 1));
            }

            if ($unit != 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', "bytes $start-$end/$size")
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);
        }

        $response
            ->setContentType($contentType)
            ->addHeader('Content-Security-Policy', 'script-src none;')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Content-Disposition', 'inline; filename="' . $file->getAttribute('name', '') . '"')
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->addHeader('X-Peak', \memory_get_peak_usage())
        ;

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $deviceForFiles->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($file->getAttribute('algorithm', Compression::NONE)) {
            case Compression::ZSTD:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case Compression::GZIP:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        if (!empty($source)) {
            if (!empty($rangeHeader)) {
                $response->send(substr($source, $start, ($end - $start + 1)));
                return;
            }
            $response->send($source);
            return;
        }

        if (!empty($rangeHeader)) {
            $response->send($deviceForFiles->read($path, $start, ($end - $start + 1)));
            return;
        }

        $size = $deviceForFiles->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForFiles->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceForFiles->read($path));
        }
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/push')
    ->desc('Get file for push notification')
    ->groups(['api', 'storage'])
    ->label('scope', 'public')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->param('jwt', '', new Text(2048, 0), 'JSON Web Token to validate', true)
    ->inject('response')
    ->inject('request')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('mode')
    ->inject('deviceForFiles')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, string $jwt, Response $response, Request $request, Database $dbForProject, Database $dbForPlatform, Document $project, string $mode, Device $deviceForFiles, Authorization $authorization) {
        $decoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

        try {
            $decoded = $decoder->decode($jwt);
        } catch (JWTException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if (
            $decoded['projectId'] !== $project->getId() ||
            $decoded['bucketId'] !== $bucketId ||
            $decoded['fileId'] !== $fileId
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $isInternal = $decoded['internal'] ?? false;
        $disposition = $decoded['disposition'] ?? 'inline';
        $dbForProject = $isInternal ? $dbForPlatform : $dbForProject;

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));
        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $mimes = Config::getParam('storage-mimes');

        $path = $file->getAttribute('path', '');
        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        $contentType = 'text/plain';

        if (\in_array($file->getAttribute('mimeType'), $mimes)) {
            $contentType = $file->getAttribute('mimeType');
        }

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null || $end - $start > APP_STORAGE_READ_BUFFER) {
                $end = min(($start + APP_STORAGE_READ_BUFFER - 1), ($size - 1));
            }

            if ($unit != 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', "bytes $start-$end/$size")
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);
        }

        $response
            ->setContentType($contentType)
            ->addHeader('Content-Security-Policy', 'script-src none;')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Content-Disposition', $disposition . '; filename="' . $file->getAttribute('name', '') . '"')
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->addHeader('X-Peak', \memory_get_peak_usage());

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $deviceForFiles->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($file->getAttribute('algorithm', Compression::NONE)) {
            case Compression::ZSTD:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case Compression::GZIP:
                if (empty($source)) {
                    $source = $deviceForFiles->read($path);
                }
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        if (!empty($source)) {
            if (!empty($rangeHeader)) {
                $response->send(substr($source, $start, ($end - $start + 1)));
                return;
            }
            $response->send($source);
            return;
        }

        if (!empty($rangeHeader)) {
            $response->send($deviceForFiles->read($path, $start, ($end - $start + 1)));
            return;
        }

        $size = $deviceForFiles->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForFiles->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceForFiles->read($path));
        }
    });

App::put('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId')
    ->desc('Update file')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('event', 'buckets.[bucketId].files.[fileId].update')
    ->label('audits.event', 'file.update')
    ->label('audits.resource', 'file/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'updateFile',
        description: '/docs/references/storage/update-file.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_FILE,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->param('name', null, new Nullable(new Text(255)), 'Name of the file', true)
    ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permission string. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('mode')
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, ?string $name, ?array $permissions, Response $response, Database $dbForProject, Document $user, string $mode, Event $queueForEvents, Authorization $authorization) {

        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_UPDATE, $bucket->getUpdate()));
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        // Read permission should not be required for update
        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!User::isApp($roles) && !User::isPrivileged($roles) && !\is_null($permissions)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!$authorization->hasRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        if (\is_null($permissions)) {
            $permissions = $file->getPermissions() ?? [];
        }

        $file->setAttribute('$permissions', $permissions);

        if (!is_null($name)) {
            $file->setAttribute('name', $name);
        }

        try {
            if ($fileSecurity && !$valid) {
                $file = $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file);
            } else {
                $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file));
            }
        } catch (NotFoundException) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
        ;

        $response->dynamic($file, Response::MODEL_FILE);
    });

App::delete('/v1/storage/buckets/:bucketId/files/:fileId')
    ->desc('Delete file')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('event', 'buckets.[bucketId].files.[fileId].delete')
    ->label('audits.event', 'file.delete')
    ->label('audits.resource', 'file/{request.fileId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: 'files',
        name: 'deleteFile',
        description: '/docs/references/storage/delete-file.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('mode')
    ->inject('deviceForFiles')
    ->inject('queueForDeletes')
    ->inject('authorization')
    ->action(function (string $bucketId, string $fileId, Response $response, Database $dbForProject, Event $queueForEvents, string $mode, Device $deviceForFiles, Delete $queueForDeletes, Authorization $authorization) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_DELETE, $bucket->getDelete()));
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        // Read permission should not be required for delete
        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Make sure we don't delete the file before the document permission check occurs
        $validFile = $authorization->isValid(new Input(Database::PERMISSION_DELETE, $file->getDelete()));
        if ($fileSecurity && !$valid && !$validFile) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $deviceDeleted = false;
        if ($file->getAttribute('chunksTotal') !== $file->getAttribute('chunksUploaded')) {
            $deviceDeleted = $deviceForFiles->abort(
                $file->getAttribute('path'),
                ($file->getAttribute('metadata', [])['uploadId'] ?? '')
            );
        } else {
            $deviceDeleted = $deviceForFiles->delete($file->getAttribute('path'));
        }

        if ($deviceDeleted) {
            $queueForDeletes
                ->setType(DELETE_TYPE_CACHE_BY_RESOURCE)
                ->setResourceType('bucket/' . $bucket->getId())
                ->setResource('file/' . $fileId)
            ;

            try {
                if ($fileSecurity && !$valid) {
                    $deleted = $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId);
                } else {
                    $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId));
                }
            } catch (NotFoundException) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!$deleted) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
            }
        } else {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to delete file from device');
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($file, Response::MODEL_FILE))
        ;

        $response->noContent();
    });

/** Storage usage */
App::get('/v1/storage/usage')
    ->desc('Get storage usage stats')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: null,
        name: 'getUsage',
        description: '/docs/references/storage/get-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_STORAGE,
            )
        ]
    ))
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $range, Response $response, Database $dbForProject, Authorization $authorization) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_BUCKETS,
            METRIC_FILES,
            METRIC_FILES_STORAGE,
        ];

        $total = [];
        $authorization->skip(function () use ($dbForProject, $days, $metrics, &$stats, &$total) {
            foreach ($metrics as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }
        $response->dynamic(new Document([
            'range' => $range,
            'bucketsTotal' => $usage[$metrics[0]]['total'],
            'filesTotal' => $usage[$metrics[1]]['total'],
            'filesStorageTotal' => $usage[$metrics[2]]['total'],
            'buckets' => $usage[$metrics[0]]['data'],
            'files' => $usage[$metrics[1]]['data'],
            'storage' => $usage[$metrics[2]]['data'],
        ]), Response::MODEL_USAGE_STORAGE);
    });

App::get('/v1/storage/:bucketId/usage')
    ->desc('Get bucket usage stats')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('resourceType', RESOURCE_TYPE_BUCKETS)
    ->label('sdk', new Method(
        namespace: 'storage',
        group: null,
        name: 'getBucketUsage',
        description: '/docs/references/storage/get-bucket-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_BUCKETS,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Bucket ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('getLogsDB')
    ->inject('authorization')
    ->action(function (string $bucketId, string $range, Response $response, Document $project, Database $dbForProject, callable $getLogsDB, Authorization $authorization) {

        $dbForLogs = call_user_func($getLogsDB, $project);
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES),
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_STORAGE),
            str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED),
        ];

        $authorization->skip(function () use ($dbForProject, $dbForLogs, $bucket, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $db = ($metric === str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED))
                    ? $dbForLogs
                    : $dbForProject;

                $result = $db->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $db->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });


        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'filesTotal' => $usage[$metrics[0]]['total'],
            'filesStorageTotal' => $usage[$metrics[1]]['total'],
            'files' => $usage[$metrics[0]]['data'],
            'storage' => $usage[$metrics[1]]['data'],
            'imageTransformations' => $usage[$metrics[2]]['data'],
            'imageTransformationsTotal' => $usage[$metrics[2]]['total'],
        ]), Response::MODEL_USAGE_BUCKETS);
    });
