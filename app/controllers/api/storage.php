<?php

use Appwrite\Auth\Auth;
use Appwrite\ClamAV\Network;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Buckets;
use Appwrite\Utopia\Database\Validator\Queries\Files;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Swoole\Request;

App::post('/v1/storage/buckets')
    ->desc('Create bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('event', 'buckets.[bucketId].create')
    ->label('audits.event', 'bucket.create')
    ->label('audits.resource', 'bucket/{response.$id}')
    ->label('usage.metric', 'buckets.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'createBucket')
    ->label('sdk.description', '/docs/references/storage/create-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
    ->param('bucketId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Bucket name')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default no user is granted with any permissions. [Learn more about permissions](/docs/permissions).', true)
    ->param('fileSecurity', false, new Boolean(true), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](/docs/permissions).', true)
    ->param('enabled', true, new Boolean(true), 'Is bucket enabled?', true)
    ->param('maximumFileSize', (int) App::getEnv('_APP_STORAGE_LIMIT', 0), new Range(1, (int) App::getEnv('_APP_STORAGE_LIMIT', 0)), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '. For self-hosted setups you can change the max limit by changing the `_APP_STORAGE_LIMIT` environment variable. [Learn more about storage environment variables](docs/environment-variables#storage)', true)
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
    ->param('compression', COMPRESSION_TYPE_NONE, new WhiteList([COMPRESSION_TYPE_NONE, COMPRESSION_TYPE_GZIP, COMPRESSION_TYPE_ZSTD]), 'Compression algorithm choosen for compression. Can be one of ' . COMPRESSION_TYPE_NONE . ',  [' . COMPRESSION_TYPE_GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . COMPRESSION_TYPE_ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
    ->param('encryption', true, new Boolean(true), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antivirus', true, new Boolean(true), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $bucketId, string $name, ?array $permissions, bool $fileSecurity, bool $enabled, int $maximumFileSize, array $allowedFileExtensions, string $compression, bool $encryption, bool $antivirus, Response $response, Database $dbForProject, Event $events) {

        $bucketId = $bucketId === 'unique()' ? ID::unique() : $bucketId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        try {
            $files = Config::getParam('collections', [])['files'] ?? [];
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
                'search' => implode(' ', [$bucketId, $name]),
            ]));

            $bucket = $dbForProject->getDocument('buckets', $bucketId);

            $dbForProject->createCollection('bucket_' . $bucket->getInternalId(), $attributes, $indexes);
        } catch (Duplicate) {
            throw new Exception(Exception::STORAGE_BUCKET_ALREADY_EXISTS);
        }

        $events
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
    ->label('usage.metric', 'buckets.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'listBuckets')
    ->label('sdk.description', '/docs/references/storage/list-buckets.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET_LIST)
    ->param('queries', [], new Buckets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Buckets::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $bucketId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('buckets', $bucketId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Bucket '{$bucketId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'buckets' => $dbForProject->find('buckets', $queries),
            'total' => $dbForProject->count('buckets', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_BUCKET_LIST);
    });

App::get('/v1/storage/buckets/:bucketId')
    ->desc('Get Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('usage.metric', 'buckets.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getBucket')
    ->label('sdk.description', '/docs/references/storage/get-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
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
    ->desc('Update Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('event', 'buckets.[bucketId].update')
    ->label('audits.event', 'bucket.update')
    ->label('audits.resource', 'bucket/{response.$id}')
    ->label('usage.metric', 'buckets.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'updateBucket')
    ->label('sdk.description', '/docs/references/storage/update-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->param('name', null, new Text(128), 'Bucket name', false)
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default the current permissions are inherited. [Learn more about permissions](/docs/permissions).', true)
    ->param('fileSecurity', false, new Boolean(true), 'Enables configuring permissions for individual file. A user needs one of file or bucket level permissions to access a file. [Learn more about permissions](/docs/permissions).', true)
    ->param('enabled', true, new Boolean(true), 'Is bucket enabled?', true)
    ->param('maximumFileSize', null, new Range(1, (int) App::getEnv('_APP_STORAGE_LIMIT', 0)), 'Maximum file size allowed in bytes. Maximum allowed value is ' . Storage::human((int)App::getEnv('_APP_STORAGE_LIMIT', 0), 0) . '. For self hosted version you can change the limit by changing _APP_STORAGE_LIMIT environment variable. [Learn more about storage environment variables](docs/environment-variables#storage)', true)
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Allowed file extensions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' extensions are allowed, each 64 characters long.', true)
    ->param('compression', COMPRESSION_TYPE_NONE, new WhiteList([COMPRESSION_TYPE_NONE, COMPRESSION_TYPE_GZIP, COMPRESSION_TYPE_ZSTD]), 'Compression algorithm choosen for compression. Can be one of ' . COMPRESSION_TYPE_NONE . ', [' . COMPRESSION_TYPE_GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . COMPRESSION_TYPE_ZSTD . '](https://en.wikipedia.org/wiki/Zstd), For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' compression is skipped even if it\'s enabled', true)
    ->param('encryption', true, new Boolean(true), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER, 0) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antivirus', true, new Boolean(true), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS, 0) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $bucketId, string $name, ?array $permissions, bool $fileSecurity, bool $enabled, ?int $maximumFileSize, array $allowedFileExtensions, string $compression, bool $encryption, bool $antivirus, Response $response, Database $dbForProject, Event $events) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $permissions ??= $bucket->getPermissions();
        $maximumFileSize ??= $bucket->getAttribute('maximumFileSize', (int) App::getEnv('_APP_STORAGE_LIMIT', 0));
        $allowedFileExtensions ??= $bucket->getAttribute('allowedFileExtensions', []);
        $enabled ??= $bucket->getAttribute('enabled', true);
        $encryption ??= $bucket->getAttribute('encryption', true);
        $antivirus ??= $bucket->getAttribute('antivirus', true);

        /**
         * Map aggregate permissions into the multiple permissions they represent,
         * accounting for the resource type given that some types not allowed specific permissions.
         */
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
                ->setAttribute('antivirus', $antivirus));

        $events
            ->setParam('bucketId', $bucket->getId())
        ;

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::delete('/v1/storage/buckets/:bucketId')
    ->desc('Delete Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('audits.event', 'bucket.delete')
    ->label('event', 'buckets.[bucketId].delete')
    ->label('audits.resource', 'bucket/{request.bucketId}')
    ->label('usage.metric', 'buckets.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'deleteBucket')
    ->label('sdk.description', '/docs/references/storage/delete-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $bucketId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('buckets', $bucketId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove bucket from DB');
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($bucket);

        $events
            ->setParam('bucketId', $bucket->getId())
            ->setPayload($response->output($bucket, Response::MODEL_BUCKET))
        ;

        $response->noContent();
    });

App::post('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files', ['bucketId' => 'default'])
    ->desc('Create File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('audits.event', 'file.create')
    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->label('audits.resource', 'file/{response.$id}')
    ->label('usage.metric', 'files.{scope}.requests.create')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'createFile')
    ->label('sdk.description', '/docs/references/storage/create-file.md')
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.methodType', 'upload')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('file', [], new File(), 'Binary file.', false)
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permission strings. By default the current user is granted with all permissions. [Learn more about permissions](/docs/permissions).', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->inject('deletes')
    ->action(function (string $bucketId, string $fileId, mixed $file, ?array $permissions, Request $request, Response $response, Database $dbForProject, Document $user, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal, Delete $deletes) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $validator = new Authorization(Database::PERMISSION_CREATE);
        if (!$validator->isValid($bucket->getCreate())) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
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
        $roles = Authorization::getRoles();
        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
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
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        $maximumFileSize = $bucket->getAttribute('maximumFileSize', 0);
        if ($maximumFileSize > (int) App::getEnv('_APP_STORAGE_LIMIT', 0)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Maximum bucket file size is larger than _APP_STORAGE_LIMIT');
        }

        $file = $request->getFiles('file');
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
            if (is_null($start) || is_null($end) || is_null($fileSize)) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            if ($end === $fileSize) {
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
        $fileSize ??= $deviceLocal->getFileSize($fileTmpName);
        $path = $deviceFiles->getPath($fileId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $path = str_ireplace($deviceFiles->getRoot(), $deviceFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root

        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);

        $metadata = ['content_type' => $deviceLocal->getFileMimeType($fileTmpName)];
        if (!$file->isEmpty()) {
            $chunks = $file->getAttribute('chunksTotal', 1);
            $metadata = $file->getAttribute('metadata', []);
            if ($chunk === -1) {
                $chunk = $chunks;
            }
        }

        $chunksUploaded = $deviceFiles->upload($fileTmpName, $path, $chunk, $chunks, $metadata);
        if (empty($chunksUploaded)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed uploading file');
        }

        if ($chunksUploaded === $chunks) {
            if (App::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled' && $bucket->getAttribute('antivirus', true) && $fileSize <= APP_LIMIT_ANTIVIRUS && App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) === Storage::DEVICE_LOCAL) {
                $antivirus = new Network(
                    App::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                    (int) App::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
                );

                if (!$antivirus->fileScan($path)) {
                    $deviceFiles->delete($path);
                    throw new Exception(Exception::STORAGE_INVALID_FILE);
                }
            }

            $mimeType = $deviceFiles->getFileMimeType($path); // Get mime-type before compression and encryption
            $data = '';
            // Compression
            $algorithm = $bucket->getAttribute('compression', COMPRESSION_TYPE_NONE);
            if ($fileSize <= APP_STORAGE_READ_BUFFER && $algorithm != COMPRESSION_TYPE_NONE) {
                $data = $deviceFiles->read($path);
                switch ($algorithm) {
                    case COMPRESSION_TYPE_ZSTD:
                        $compressor = new Zstd();
                        break;
                    case COMPRESSION_TYPE_GZIP:
                    default:
                        $compressor = new GZIP();
                        break;
                }
                $data = $compressor->compress($data);
            }

            if ($bucket->getAttribute('encryption', true) && $fileSize <= APP_STORAGE_READ_BUFFER) {
                if (empty($data)) {
                    $data = $deviceFiles->read($path);
                }
                $key = App::getEnv('_APP_OPENSSL_KEY_V1');
                $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
                $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);
            }

            if (!empty($data)) {
                if (!$deviceFiles->write($path, $data, $mimeType)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to save file');
                }
            }

            $sizeActual = $deviceFiles->getFileSize($path);
            $fileHash = $deviceFiles->getFileHash($path);

            if ($bucket->getAttribute('encryption', true) && $fileSize <= APP_STORAGE_READ_BUFFER) {
                $openSSLVersion = '1';
                $openSSLCipher = OpenSSL::CIPHER_AES_128_GCM;
                $openSSLTag = \bin2hex($tag);
                $openSSLIV = \bin2hex($iv);
            }

            try {
                if ($file->isEmpty()) {
                    $doc = new Document([
                        '$id' => $fileId,
                        '$permissions' => $permissions,
                        'bucketId' => $bucket->getId(),
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

                    $file = $dbForProject->createDocument('bucket_' . $bucket->getInternalId(), $doc);
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

                    $file = $dbForProject->updateDocument('bucket_' . $bucket->getInternalId(), $fileId, $file);
                }
            } catch (AuthorizationException) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            } catch (StructureException $exception) {
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
            } catch (DuplicateException) {
                throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
            }
        } else {
            try {
                if ($file->isEmpty()) {
                    $doc = new Document([
                        '$id' => ID::custom($fileId),
                        '$permissions' => $permissions,
                        'bucketId' => $bucket->getId(),
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

                    $file = $dbForProject->createDocument('bucket_' . $bucket->getInternalId(), $doc);
                } else {
                    $file = $file
                        ->setAttribute('chunksUploaded', $chunksUploaded)
                        ->setAttribute('metadata', $metadata);

                    $file = $dbForProject->updateDocument('bucket_' . $bucket->getInternalId(), $fileId, $file);
                }
            } catch (AuthorizationException) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            } catch (StructureException $exception) {
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
            } catch (DuplicateException) {
                throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
            }
        }

        $events
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
        ;

        $deletes
            ->setType(DELETE_TYPE_CACHE_BY_RESOURCE)
            ->setResource('file/' . $file->getId())
        ;

        $metadata = null; // was causing leaks as it was passed by reference

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files', ['bucketId' => 'default'])
    ->desc('List Files')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('usage.metric', 'files.{scope}.requests.read')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'listFiles')
    ->label('sdk.description', '/docs/references/storage/list-files.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE_LIST)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('queries', [], new Files(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Files::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $bucketId, array $queries, string $search, Response $response, Database $dbForProject, string $mode) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $fileId = $cursor->getValue();

            if ($fileSecurity && !$valid) {
                $cursorDocument = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
            } else {
                $cursorDocument = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
            }

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "File '{$fileId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        if ($fileSecurity && !$valid) {
            $files = $dbForProject->find('bucket_' . $bucket->getInternalId(), $queries);
            $total = $dbForProject->count('bucket_' . $bucket->getInternalId(), $filterQueries, APP_LIMIT_COUNT);
        } else {
            $files = Authorization::skip(fn () => $dbForProject->find('bucket_' . $bucket->getInternalId(), $queries));
            $total = Authorization::skip(fn () => $dbForProject->count('bucket_' . $bucket->getInternalId(), $filterQueries, APP_LIMIT_COUNT));
        }

        $response->dynamic(new Document([
            'files' => $files,
            'total' => $total,
        ]), Response::MODEL_FILE_LIST);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Get File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('usage.metric', 'files.{scope}.requests.read')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFile')
    ->label('sdk.description', '/docs/references/storage/get-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $bucketId, string $fileId, Response $response, Database $dbForProject, string $mode) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
        } else {
            $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $response->dynamic($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/preview')
    ->alias('/v1/storage/files/:fileId/preview', ['bucketId' => 'default'])
    ->desc('Get File Preview')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('cache', true)
    ->label('cache.resourceType', 'bucket/{request.bucketId}')
    ->label('cache.resource', 'file/{request.fileId}')
    ->label('usage.metric', 'files.{scope}.requests.read')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFilePreview')
    ->label('sdk.description', '/docs/references/storage/get-file-preview.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->label('sdk.methodType', 'location')
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID')
    ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
    ->param('gravity', Image::GRAVITY_CENTER, new WhiteList(Image::getGravityTypes()), 'Image crop gravity. Can be one of ' . implode(",", Image::getGravityTypes()), true)
    ->param('quality', 100, new Range(0, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
    ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
    ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
    ->param('opacity', 1, new Range(0, 1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
    ->param('rotation', 0, new Range(-360, 360), 'Preview image rotation in degrees. Pass an integer between -360 and 360.', true)
    ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
    ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(function (string $bucketId, string $fileId, int $width, int $height, string $gravity, int $quality, int $borderWidth, string $borderColor, int $borderRadius, float $opacity, int $rotation, string $background, string $output, Request $request, Response $response, Document $project, Database $dbForProject, string $mode, Device $deviceFiles, Device $deviceLocal) {

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ((\strpos($request->getAccept(), 'image/webp') === false) && ('webp' === $output)) { // Fallback webp to jpeg when no browser support
            $output = 'jpg';
        }

        $inputs = Config::getParam('storage-inputs');
        $outputs = Config::getParam('storage-outputs');
        $fileLogos = Config::getParam('storage-logos');

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
        } else {
            $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $path = $file->getAttribute('path');
        $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
        $algorithm = $file->getAttribute('algorithm', 'none');
        $cipher = $file->getAttribute('openSSLCipher');
        $mime = $file->getAttribute('mimeType');
        if (!\in_array($mime, $inputs) || $file->getAttribute('sizeActual') > (int) App::getEnv('_APP_STORAGE_PREVIEW_LIMIT', 20000000)) {
            if (!\in_array($mime, $inputs)) {
                $path = (\array_key_exists($mime, $fileLogos)) ? $fileLogos[$mime] : $fileLogos['default'];
            } else {
                // it was an image but the file size exceeded the limit
                $path = $fileLogos['default_image'];
            }

            $algorithm = 'none';
            $cipher = null;
            $background = (empty($background)) ? 'eceff1' : $background;
            $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
            $deviceFiles = $deviceLocal;
        }

        if (!$deviceFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        if (empty($output)) {
            // when file extension is not provided and the mime type is not one of our supported outputs
            // we fallback to `jpg` output format
            $output = empty($type) ? (array_search($mime, $outputs) ?? 'jpg') : $type;
        }


        $source = $deviceFiles->read($path);

        if (!empty($cipher)) { // Decrypt
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($algorithm) {
            case 'zstd':
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case 'gzip':
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        $image = new Image($source);

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

        $contentType = (\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'];

        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
            ->setContentType($contentType)
            ->file($data)
        ;

        unset($image);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/download')
    ->alias('/v1/storage/files/:fileId/download', ['bucketId' => 'default'])
    ->desc('Get File for Download')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('usage.metric', 'files.{scope}.requests.read')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileDownload')
    ->label('sdk.description', '/docs/references/storage/get-file-download.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('bucketId', '', new UID(), 'Storage bucket ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deviceFiles')
    ->action(function (string $bucketId, string $fileId, Request $request, Response $response, Database $dbForProject, string $mode, Device $deviceFiles) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
        } else {
            $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $path = $file->getAttribute('path', '');

        if (!$deviceFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        $response
            ->setContentType($file->getAttribute('mimeType'))
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $file->getAttribute('name', '') . '"')
        ;

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
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

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $deviceFiles->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($file->getAttribute('algorithm', 'none')) {
            case 'zstd':
                if (empty($source)) {
                    $source = $deviceFiles->read($path);
                }
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case 'gzip':
                if (empty($source)) {
                    $source = $deviceFiles->read($path);
                }
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        if (!empty($source)) {
            if (!empty($rangeHeader)) {
                $response->send(substr($source, $start, ($end - $start + 1)));
            }
            $response->send($source);
        }

        if (!empty($rangeHeader)) {
            $response->send($deviceFiles->read($path, $start, ($end - $start + 1)));
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            $response->addHeader('Content-Length', $deviceFiles->getFileSize($path));
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceFiles->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceFiles->read($path));
        }
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/view')
    ->alias('/v1/storage/files/:fileId/view', ['bucketId' => 'default'])
    ->desc('Get File for View')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('usage.metric', 'files.{scope}.requests.read')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileView')
    ->label('sdk.description', '/docs/references/storage/get-file-view.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('request')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deviceFiles')
    ->action(function (string $bucketId, string $fileId, Response $response, Request $request, Database $dbForProject, string $mode, Device $deviceFiles) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
        } else {
            $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $mimes = Config::getParam('storage-mimes');

        $path = $file->getAttribute('path', '');

        if (!$deviceFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        $contentType = 'text/plain';

        if (\in_array($file->getAttribute('mimeType'), $mimes)) {
            $contentType = $file->getAttribute('mimeType');
        }

        $response
            ->setContentType($contentType)
            ->addHeader('Content-Security-Policy', 'script-src none;')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Content-Disposition', 'inline; filename="' . $file->getAttribute('name', '') . '"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
        ;

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
                $end = min(($start + 2000000 - 1), ($size - 1));
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

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $deviceFiles->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        switch ($file->getAttribute('algorithm', 'none')) {
            case 'zstd':
                if (empty($source)) {
                    $source = $deviceFiles->read($path);
                }
                $compressor = new Zstd();
                $source = $compressor->decompress($source);
                break;
            case 'gzip':
                if (empty($source)) {
                    $source = $deviceFiles->read($path);
                }
                $compressor = new GZIP();
                $source = $compressor->decompress($source);
                break;
        }

        if (!empty($source)) {
            if (!empty($rangeHeader)) {
                $response->send(substr($source, $start, ($end - $start + 1)));
            }
            $response->send($source);
        }

        if (!empty($rangeHeader)) {
            $response->send($deviceFiles->read($path, $start, ($end - $start + 1)));
        }

        $size = $deviceFiles->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {
            $response->addHeader('Content-Length', $deviceFiles->getFileSize($path));
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceFiles->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceFiles->read($path));
        }
    });

App::put('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Update File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('event', 'buckets.[bucketId].files.[fileId].update')
    ->label('audits.event', 'file.update')
    ->label('audits.resource', 'file/{response.$id}')
    ->label('usage.metric', 'files.{scope}.requests.update')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'updateFile')
    ->label('sdk.description', '/docs/references/storage/update-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permission string. By default the current permissions are inherited. [Learn more about permissions](/docs/permissions).', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('mode')
    ->inject('events')
    ->action(function (string $bucketId, string $fileId, ?array $permissions, Response $response, Database $dbForProject, Document $user, string $mode, Event $events) {

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttributes('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_UPDATE);
        $valid = $validator->isValid($bucket->getUpdate());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for update
        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));

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
        $roles = Authorization::getRoles();
        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles) && !\is_null($permissions)) {
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
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        if (\is_null($permissions)) {
            $permissions = $file->getPermissions() ?? [];
        }

        $file->setAttribute('$permissions', $permissions);

        if ($fileSecurity && !$valid) {
            try {
                $file = $dbForProject->updateDocument('bucket_' . $bucket->getInternalId(), $fileId, $file);
            } catch (AuthorizationException) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }
        } else {
            $file = Authorization::skip(fn() => $dbForProject->updateDocument('bucket_' . $bucket->getInternalId(), $fileId, $file));
        }

        $events
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
        ;

        $response->dynamic($file, Response::MODEL_FILE);
    });

App::delete('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Delete File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('event', 'buckets.[bucketId].files.[fileId].delete')
    ->label('audits.event', 'file.delete')
    ->label('audits.resource', 'file/{request.fileId}')
    ->label('usage.metric', 'files.{scope}.requests.delete')
    ->label('usage.params', ['bucketId:{request.bucketId}'])
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'deleteFile')
    ->label('sdk.description', '/docs/references/storage/delete-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deletes')
    ->action(function (string $bucketId, string $fileId, Response $response, Database $dbForProject, Event $events, string $mode, Device $deviceFiles, Delete $deletes) {
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttributes('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_DELETE);
        $valid = $validator->isValid($bucket->getDelete());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for delete
        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Make sure we don't delete the file before the document permission check occurs
        if ($fileSecurity && !$valid && !$validator->isValid($file->getDelete())) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $deviceDeleted = false;
        if ($file->getAttribute('chunksTotal') !== $file->getAttribute('chunksUploaded')) {
            $deviceDeleted = $deviceFiles->abort(
                $file->getAttribute('path'),
                ($file->getAttribute('metadata', [])['uploadId'] ?? '')
            );
        } else {
            $deviceDeleted = $deviceFiles->delete($file->getAttribute('path'));
        }

        if ($deviceDeleted) {
            $deletes
                ->setType(DELETE_TYPE_CACHE_BY_RESOURCE)
                ->setResource('file/' . $fileId)
            ;

            if ($fileSecurity && !$valid) {
                try {
                    $deleted = $dbForProject->deleteDocument('bucket_' . $bucket->getInternalId(), $fileId);
                } catch (AuthorizationException) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }
            } else {
                $deleted = Authorization::skip(fn() => $dbForProject->deleteDocument('bucket_' . $bucket->getInternalId(), $fileId));
            }

            if (!$deleted) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
            }
        } else {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to delete file from device');
        }

        $events
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($file, Response::MODEL_FILE))
        ;

        $response->noContent();
    });

App::get('/v1/storage/usage')
    ->desc('Get usage stats for storage')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_STORAGE)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'project.$all.storage.size',
                'buckets.$all.count.total',
                'buckets.$all.requests.create',
                'buckets.$all.requests.read',
                'buckets.$all.requests.update',
                'buckets.$all.requests.delete',
                'files.$all.storage.size',
                'files.$all.count.total',
                'files.$all.requests.create',
                'files.$all.requests.read',
                'files.$all.requests.update',
                'files.$all.requests.delete',
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'bucketsCount' => $stats['buckets.$all.count.total'],
                'bucketsCreate' => $stats['buckets.$all.requests.create'],
                'bucketsRead' => $stats['buckets.$all.requests.read'],
                'bucketsUpdate' => $stats['buckets.$all.requests.update'],
                'bucketsDelete' => $stats['buckets.$all.requests.delete'],
                'storage' => $stats['project.$all.storage.size'],
                'filesCount' => $stats['files.$all.count.total'],
                'filesCreate' => $stats['files.$all.requests.create'],
                'filesRead' => $stats['files.$all.requests.read'],
                'filesUpdate' => $stats['files.$all.requests.update'],
                'filesDelete' => $stats['files.$all.requests.delete'],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_STORAGE);
    });

App::get('/v1/storage/:bucketId/usage')
    ->desc('Get usage stats for a storage bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getBucketUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_BUCKETS)
    ->param('bucketId', '', new UID(), 'Bucket ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $bucketId, string $range, Response $response, Database $dbForProject) {

        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                "files.{$bucketId}.count.total",
                "files.{$bucketId}.storage.size",
                "files.{$bucketId}.requests.create",
                "files.{$bucketId}.requests.read",
                "files.{$bucketId}.requests.update",
                "files.{$bucketId}.requests.delete",
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'filesCount' => $stats[$metrics[0]],
                'filesStorage' => $stats[$metrics[1]],
                'filesCreate' => $stats[$metrics[2]],
                'filesRead' => $stats[$metrics[3]],
                'filesUpdate' => $stats[$metrics[4]],
                'filesDelete' => $stats[$metrics[5]],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_BUCKETS);
    });
