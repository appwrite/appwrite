<?php

use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Appwrite\ClamAV\Network;
use Utopia\Database\Validator\Authorization;
use Appwrite\Database\Validator\CustomId;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Exception;
use Utopia\Database\Validator\UID;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Image\Image;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Exception\Duplicate;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

use function PHPUnit\Framework\isNull;

App::post('/v1/storage/buckets')
    ->desc('Create storage bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('event', 'storage.buckets.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'createBucket')
    ->label('sdk.description', '/docs/references/storage/create-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
    ->param('bucketId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Bucket name', false)
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('maximumFileSize', (int) App::getEnv('_APP_STORAGE_LIMIT', 0), new Integer(), 'Maximum file size allowed in bytes. Maximum allowed value is ' . App::getEnv('_APP_STORAGE_LIMIT', 0) . '. For self-hosted setups you can change the max limit by changing the `_APP_STORAGE_LIMIT` environment variable. [Learn more about storage environment variables](docs/environment-variables#storage)', true)
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64)), 'Allowed file extensions', true)
    ->param('enabled', true, new Boolean(), 'Is bucket enabled?', true)
    ->param('adapter', 'local', new WhiteList(['local']), 'Storage adapter.', true)
    ->param('encryption', true, new Boolean(), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antiVirus', true, new Boolean(), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $name, $read, $write, $maximumFileSize, $allowedFileExtensions, $enabled, $adapter, $encryption, $antiVirus, $response, $dbForInternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $bucketId = $bucketId == 'unique()' ? $dbForInternal->getId() : $bucketId;
        try {
            $dbForInternal->createCollection('bucket_' . $bucketId, [
                new Document([
                    '$id' => 'dateCreated',
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    'array' => false,
                    '$id' => 'bucketId',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => Database::LENGTH_KEY,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'name',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'path',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'signature',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'mimeType',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 127, // https://tools.ietf.org/html/rfc4288#section-4.2
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'sizeOriginal',
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'sizeActual',
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'algorithm',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 255,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'comment',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'openSSLVersion',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 64,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'openSSLCipher',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 64,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'openSSLTag',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'openSSLIV',
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 2048,
                    'signed' => true,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'chunksTotal',
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => false,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'chunksUploaded',
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => false,
                    'required' => false,
                    'default' => null,
                    'array' => false,
                    'filters' => [],
                ]),
            ], [
                new Document([
                    '$id' => '_key_bucket',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['bucketId'],
                    'lengths' => [Database::LENGTH_KEY],
                    'orders' => [Database::ORDER_ASC],
                ]),
                new Document([
                    '$id' => '_fulltext_name',
                    'type' => Database::INDEX_FULLTEXT,
                    'attributes' => ['name'],
                    'lengths' => [1024],
                    'orders' => [Database::ORDER_ASC],
                ]),
            ]);

            $bucket = $dbForInternal->createDocument('buckets', new Document([
                '$id' => $bucketId,
                '$collection' => 'buckets',
                'dateCreated' => \time(),
                'dateUpdated' => \time(),
                'name' => $name,
                'maximumFileSize' => $maximumFileSize,
                'allowedFileExtensions' => $allowedFileExtensions,
                'enabled' => $enabled,
                'adapter' => $adapter,
                'encryption' => $encryption,
                'antiVirus' => $antiVirus,
                '$read' => $read,
                '$write' => $write,
            ]));
        } catch (Duplicate $th) {
            throw new Exception('Bucket already exists', 409);
        }

        $audits
            ->setParam('event', 'storage.buckets.create')
            ->setParam('resource', 'storage/buckets/' . $bucket->getId())
            ->setParam('data', $bucket->getArrayCopy())
        ;

        $usage->setParam('storage.buckets.create', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::get('/v1/storage/buckets')
    ->desc('List buckets')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'listBuckets')
    ->label('sdk.description', '/docs/references/storage/list-buckets.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('after', '', new UID(), 'ID of the bucket used as the starting point for the query, excluding the bucket itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($search, $limit, $offset, $after, $orderType, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $queries = ($search) ? [new Query('name', Query::TYPE_SEARCH, $search)] : [];
        
        if (!empty($after)) {
            $afterBucket = $dbForInternal->getDocument('buckets', $after);

            if ($afterBucket->isEmpty()) {
                throw new Exception("Bucket '{$after}' for the 'after' value not found.", 400);
            }
        }

        $usage->setParam('storage.buckets.read', 1);

        $response->dynamic(new Document([
            'buckets' => $dbForInternal->find('buckets', $queries, $limit, $offset, [], [$orderType], $afterBucket ?? null),
            'sum' => $dbForInternal->count('buckets', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_BUCKET_LIST);
    });

App::get('/v1/storage/buckets/:bucketId')
    ->desc('Get Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getBucket')
    ->label('sdk.description', '/docs/references/storage/get-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $usage->setParam('storage.buckets.read', 1);

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::put('/v1/storage/buckets/:bucketId')
    ->desc('Update Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('event', 'storage.buckets.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'updateBucket')
    ->label('sdk.description', '/docs/references/storage/update-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUCKET)
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->param('name', null, new Text(128), 'Bucket name', false)
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('maximumFileSize', null, new Integer(), 'Maximum file size allowed in bytes. Maximum allowed value is ' . App::getEnv('_APP_STORAGE_LIMIT', 0) . '. For self hosted version you can change the limit by changing _APP_STORAGE_LIMIT environment variable. [Learn more about storage environment variables](docs/environment-variables#storage)', true)
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64)), 'Allowed file extensions', true)
    ->param('enabled', true, new Boolean(), 'Is bucket enabled?', true)
    ->param('encryption', true, new Boolean(), 'Is encryption enabled? For file size above ' . Storage::human(APP_STORAGE_READ_BUFFER) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antiVirus', true, new Boolean(), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $name, $read, $write, $maximumFileSize, $allowedFileExtensions, $enabled, $encryption, $antiVirus, $response, $dbForInternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $read ??= $bucket->getAttribute('$read', []); // By default inherit read permissions
        $write??=$bucket->getAttribute('$write', []); // By default inherit write permissions
        $read ??= $bucket->getAttribute('$read', []); // By default inherit read permissions
        $write ??= $bucket->getAttribute('$write',[]); // By default inherit write permissions
        $read ??= $bucket->getAttribute('$read', []); // By default inherit read permissions
        $write??=$bucket->getAttribute('$write', []); // By default inherit write permissions
        $maximumFileSize??=$bucket->getAttribute('maximumFileSize', (int)App::getEnv('_APP_STORAGE_LIMIT', 0));
        $allowedFileExtensions??=$bucket->getAttribute('allowedFileExtensions', []);
        $enabled??=$bucket->getAttribute('enabled', true);
        $encryption??=$bucket->getAttribute('encryption', true);
        $antiVirus ??= $bucket->getAttribute('antiVirus', true);

        $bucket = $dbForInternal->updateDocument('buckets', $bucket->getId(), $bucket
                ->setAttribute('name', $name)
                ->setAttribute('$read', $read)
                ->setAttribute('$write', $write)
                ->setAttribute('maximumFileSize', $maximumFileSize)
                ->setAttribute('allowedFileExtensions', $allowedFileExtensions)
                ->setAttribute('enabled', $enabled)
                ->setAttribute('encryption', $encryption)
                ->setAttribute('antiVirus', $antiVirus)
        );

        $audits
            ->setParam('event', 'storage.buckets.update')
            ->setParam('resource', 'storage/buckets/' . $bucket->getId())
            ->setParam('data', $bucket->getArrayCopy())
        ;

        $usage->setParam('storage.buckets.update', 1);

        $response->dynamic($bucket, Response::MODEL_BUCKET);
    });

App::delete('/v1/storage/buckets/:bucketId')
    ->desc('Delete Bucket')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.write')
    ->label('event', 'storage.buckets.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'deleteBucket')
    ->label('sdk.description', '/docs/references/storage/delete-bucket.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->inject('deletes')
    ->inject('events')
    ->inject('usage')
    ->action(function ($bucketId, $response, $dbForInternal, $audits, $deletes, $events, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $deletes */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $bucket)
        ;

        if (!$dbForInternal->deleteDocument('buckets', $bucketId)) {
            throw new Exception('Failed to remove project from DB', 500);
        }

        $events
            ->setParam('eventData', $response->output($bucket, Response::MODEL_BUCKET))
        ;

        $audits
            ->setParam('event', 'storage.buckets.delete')
            ->setParam('resource', 'storage/buckets/' . $bucket->getId())
            ->setParam('data', $bucket->getArrayCopy())
        ;

        $usage->setParam('storage.buckets.delete', 1);

        $response->noContent();
    });

App::post('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files', ['bucketId' => 'default'])
    ->desc('Create File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('event', 'storage.files.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'createFile')
    ->label('sdk.description', '/docs/references/storage/create-file.md')
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.methodType', 'upload')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('file', [], new File(), 'Binary file.', false)
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $file, $read, $write, $request, $response, $dbForInternal, $user, $audits, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $request->getFiles('file');

        /**
         * Validators
         */
        $allowedFileExtensions = $bucket->getAttribute('allowedFileExtensions', []);
        $fileExt = new FileExt($allowedFileExtensions);

        $maximumFileSize = $bucket->getAttribute('maximumFileSize', 0);
        if ($maximumFileSize > (int) App::getEnv('_APP_STORAGE_LIMIT', 0)) {
            throw new Exception('Error bucket maximum file size is larger than _APP_STORAGE_LIMIT', 500);
        }

        $fileSize = new FileSize($maximumFileSize);
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400);
        }

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $size = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        $contentRange = $request->getHeader('content-range');
        $fileId = $fileId == 'unique()' ? $dbForInternal->getId() : $fileId;
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $size = $request->getContentRangeSize();

            if(is_null($start) || is_null($end) || is_null($size)) {
                throw new Exception('Invalid content-range header', 400);
            }

            if ($end == $size) {
                //if it's a last chunks the chunk size might differ, so we set the $chunks and $chunk to notify it's last chunk
                $chunks = $chunk = -1;
            } else {
                // Calculate total number of chunks based on the chunk size i.e ($rangeEnd - $rangeStart)
                $chunks = (int) ceil($size / ($end + 1 - $start));
                $chunk = (int) ($start / ($end + 1 - $start));
            }
        }

        // Check if file type is allowed (feature for project settings?)
        if (!empty($allowedFileExtensions) && !$fileExt->isValid($fileName)) {
            throw new Exception('File extension not allowed', 400);
        }

        if (!$fileSize->isValid($size)) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400);
        }

        $device = Storage::getDevice('files');

        if (!$upload->isValid($fileTmpName)) {
            throw new Exception('Invalid file', 403);
        }

        // Save to storage
        $size ??= $device->getFileSize($fileTmpName);
        $path = $device->getPath($fileId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $path = str_ireplace($device->getRoot(), $device->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path);

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if (!$file->isEmpty()) {
            $chunks = $file->getAttribute('chunksTotal', 1);
            if ($chunk == -1) {
                $chunk = $chunks - 1;
            }
        }

        $chunksUploaded = $device->upload($fileTmpName, $path, $chunk, $chunks);
        if (empty($chunksUploaded)) {
            throw new Exception('Failed uploading file', 500);
        }

        $read = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? [];
        $write = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [];
        if ($chunksUploaded == $chunks) {
            if (App::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled' && $bucket->getAttribute('antiVirus', true) && $size <= APP_LIMIT_ANTIVIRUS) {
                $antiVirus = new Network(App::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                (int) App::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310));
                
                if (!$antiVirus->fileScan($path)) {
                    $device->delete($path);
                    throw new Exception('Invalid file', 403);
                }
            }

            $mimeType = $device->getFileMimeType($path); // Get mime-type before compression and encryption
            $data = '';
            // Compression
            if ($size <= APP_STORAGE_READ_BUFFER) {
                $data = $device->read($path);
                $compressor = new GZIP();
                $data = $compressor->compress($data);
            }

            if ($bucket->getAttribute('encryption', true) && $size <= APP_STORAGE_READ_BUFFER) {
                if(empty($data)) {
                    $data = $device->read($path);
                }
                $key = App::getEnv('_APP_OPENSSL_KEY_V1');
                $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
                $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);
            }

            if(!empty($data)) {
                if (!$device->write($path, $data, $mimeType)) {
                    throw new Exception('Failed to save file', 500);
                }
            }

            $sizeActual = $device->getFileSize($path);

            $algorithm = empty($compressor) ? '' : $compressor->getName();
            $fileHash = $device->getFileHash($path);

            if ($bucket->getAttribute('encryption', true) && $size <= APP_STORAGE_READ_BUFFER) {
                $openSSLVersion = '1';
                $openSSLCipher = OpenSSL::CIPHER_AES_128_GCM;
                $openSSLTag = \bin2hex($tag);
                $openSSLIV = \bin2hex($iv);
            }

            if ($file->isEmpty()) {
                $file = $dbForInternal->createDocument('bucket_' . $bucketId, new Document([
                    '$id' => $fileId,
                    '$read' => $read,
                    '$write' => $write,
                    'dateCreated' => \time(),
                    'bucketId' => $bucket->getId(),
                    'name' => $fileName,
                    'path' => $path,
                    'signature' => $fileHash,
                    'mimeType' => $mimeType,
                    'sizeOriginal' => $size,
                    'sizeActual' => $sizeActual,
                    'algorithm' => $algorithm,
                    'comment' => '',
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                    'openSSLVersion' => $openSSLVersion,
                    'openSSLCipher' => $openSSLCipher,
                    'openSSLTag' => $openSSLTag,
                    'openSSLIV' => $openSSLIV,
                ]));
            } else {
                $file = $dbForInternal->updateDocument('bucket_' . $bucketId, $fileId, $file
                        ->setAttribute('$read', $read)
                        ->setAttribute('$write', $write)
                        ->setAttribute('signature', $fileHash)
                        ->setAttribute('mimeType', $mimeType)
                        ->setAttribute('sizeActual', $sizeActual)
                        ->setAttribute('algorithm', $algorithm)
                        ->setAttribute('openSSLVersion', $openSSLVersion)
                        ->setAttribute('openSSLCipher', $openSSLCipher)
                        ->setAttribute('openSSLTag', $openSSLTag)
                        ->setAttribute('openSSLIV', $openSSLIV)
                );
            }
        } else {
            if ($file->isEmpty()) {
                $file = $dbForInternal->createDocument('bucket_' . $bucketId, new Document([
                    '$id' => $fileId,
                    '$read' => $read,
                    '$write' => $write,
                    'dateCreated' => \time(),
                    'bucketId' => $bucket->getId(),
                    'name' => $fileName,
                    'path' => $path,
                    'signature' => '',
                    'mimeType' => '',
                    'sizeOriginal' => $size,
                    'sizeActual' => 0,
                    'algorithm' => '',
                    'comment' => '',
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                ]));
            } else {
                $file = $dbForInternal->updateDocument('bucket_' . $bucketId, $fileId, $file
                        ->setAttribute('chunksUploaded', $chunksUploaded)
                );
            }
        }

        $audits
            ->setParam('event', 'storage.files.create')
            ->setParam('resource', 'storage/files/' . $file->getId())
        ;

        $usage
            ->setParam('storage', $sizeActual)
            ->setParam('storage.files.create', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($file, Response::MODEL_FILE);
        ;
    });

App::get('/v1/storage/buckets/:bucketId/files')
    ->alias('/v1/storage/files', ['bucketId' => 'default'])
    ->desc('List Files')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'listFiles')
    ->label('sdk.description', '/docs/references/storage/list-files.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE_LIST)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('after', '', new UID(), 'ID of the file used as the starting point for the query, excluding the file itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $search, $limit, $offset, $after, $orderType, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $queries = [new Query('bucketId', Query::TYPE_EQUAL, [$bucketId])];

        if ($search) {
            $queries[] = [new Query('name', Query::TYPE_SEARCH, [$search])];
        }

        if (!empty($after)) {
            $afterFile = $dbForInternal->getDocument('bucket_' . $bucketId, $after);

            if ($afterFile->isEmpty()) {
                throw new Exception("File '{$after}' for the 'after' value not found.", 400);
            }
        }

        $usage
            ->setParam('storage.files.read', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $response->dynamic(new Document([
            'files' => $dbForInternal->find('bucket_' . $bucketId, $queries, $limit, $offset, [], [$orderType], $afterFile ?? null),
            'sum' => $dbForInternal->count('bucket_' . $bucketId, $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FILE_LIST);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Get File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFile')
    ->label('sdk.description', '/docs/references/storage/get-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }
        $usage
            ->setParam('storage.files.read', 1)
            ->setParam('bucketId', $bucketId)
        ;
        $response->dynamic($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/preview')
    ->alias('/v1/storage/files/:fileId/preview', ['bucketId' => 'default'])
    ->desc('Get File Preview')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFilePreview')
    ->label('sdk.description', '/docs/references/storage/get-file-preview.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->label('sdk.methodType', 'location')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID')
    ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
    ->param('gravity', Image::GRAVITY_CENTER, new WhiteList(Image::getGravityTypes()), 'Image crop gravity. Can be one of ' . implode(",", Image::getGravityTypes()), true)
    ->param('quality', 100, new Range(0, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
    ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
    ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
    ->param('opacity', 1, new Range(0, 1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
    ->param('rotation', 0, new Range(0, 360), 'Preview image rotation in degrees. Pass an integer between 0 and 360.', true)
    ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
    ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $width, $height, $gravity, $quality, $borderWidth, $borderColor, $borderRadius, $opacity, $rotation, $background, $output, $request, $response, $project, $dbForInternal, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $storage = 'files';

        if (!\extension_loaded('imagick')) {
            throw new Exception('Imagick extension is missing', 500);
        }

        if (!Storage::exists($storage)) {
            throw new Exception('No such storage device', 400);
        }
        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        if ((\strpos($request->getAccept(), 'image/webp') === false) && ('webp' == $output)) { // Fallback webp to jpeg when no browser support
            $output = 'jpg';
        }

        $inputs = Config::getParam('storage-inputs');
        $outputs = Config::getParam('storage-outputs');
        $fileLogos = Config::getParam('storage-logos');

        $date = \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT'; // 45 days cache
        $key = \md5($fileId . $width . $height . $quality . $borderWidth . $borderColor . $borderRadius . $opacity . $rotation . $background . $storage . $output);

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path');
        $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
        $algorithm = $file->getAttribute('algorithm');
        $cipher = $file->getAttribute('openSSLCipher');
        $mime = $file->getAttribute('mimeType');

        if (!\in_array($mime, $inputs)) {
            $path = (\array_key_exists($mime, $fileLogos)) ? $fileLogos[$mime] : $fileLogos['default'];
            $algorithm = null;
            $cipher = null;
            $background = (empty($background)) ? 'eceff1' : $background;
            $type = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));
            $key = \md5($path . $width . $height . $quality . $borderWidth . $borderColor . $borderRadius . $opacity . $rotation . $background . $storage . $output);
        }

        $compressor = new GZIP();
        $device = Storage::getDevice('files');

        if (!\file_exists($path)) {
            throw new Exception('File not found', 404);
        }

        $cache = new Cache(new Filesystem(APP_STORAGE_CACHE . '/app-' . $project->getId())); // Limit file number or size
        $data = $cache->load($key, 60 * 60 * 24 * 30 * 3/* 3 months */);

        if ($data) {
            $output = (empty($output)) ? $type : $output;

            return $response
                ->setContentType((\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'])
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'hit')
                ->send($data)
            ;
        }

        $source = $device->read($path);

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

        if (!empty($algorithm)) {
            $source = $compressor->decompress($source);
        }

        $image = new Image($source);

        $image->crop((int) $width, (int) $height, $gravity);

        if (!empty($opacity) || $opacity == 0) {
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
            $image->setRotation($rotation);
        }

        $output = (empty($output)) ? $type : $output;

        $data = $image->output($output, $quality);

        $cache->save($key, $data);

        $usage
            ->setParam('storage.files.read', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $response
            ->setContentType($outputs[$output])
            ->addHeader('Expires', $date)
            ->addHeader('X-Appwrite-Cache', 'miss')
            ->send($data)
        ;

        unset($image);
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/download')
    ->alias('/v1/storage/files/:fileId/download', ['bucketId' => 'default'])
    ->desc('Get File for Download')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileDownload')
    ->label('sdk.description', '/docs/references/storage/get-file-download.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $request, $response, $dbForInternal, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path', '');

        $device = Storage::getDevice('files');
        
        if (!$device->exists($path)) {
            throw new Exception('File not found in ' . $path, 404);
        }

        $usage
            ->setParam('storage.files.read', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $response
            ->setContentType($file->getAttribute('mimeType'))
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $file->getAttribute('name', '') . '"')
        ;

        $size = $file->getAttribute('sizeOriginal', 0);

        $rangeHeader = $request->getHeader('range');
        if(!empty($rangeHeader)) {  
            list($unit, $range) = explode('=', $rangeHeader);
            if($unit == 'bytes' && !empty($range)) {
                list($rangeStart, $rangeEnd) = explode('-', $range);
                if(strlen($rangeStart) == 0 || strstr($range, '-') === false) {
                    throw new Exception('Invalid range', 416);
                }
                $rangeStart = (int) $rangeStart;
                if(strlen($rangeEnd) == 0) {
                    $rangeEnd =  min(($rangeStart + 2000000-1), ($size - 1));
                } else {
                    $rangeEnd = (int) $rangeEnd;
                }
                if(($rangeStart >= $rangeEnd) || $rangeEnd >= $size) {
                    throw new Exception('Invalid range', 416);
                }
                
                $response
                    ->addHeader('Accept-Ranges', 'bytes')
                    ->addHeader('Content-Range', 'bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $size)
                    ->addHeader('Content-Length', $rangeEnd - $rangeStart + 1)
                    ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);
            } else {
                throw new Exception('Invalid range', 416);
            }
        }

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $device->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        if (!empty($file->getAttribute('algorithm', ''))) {
            if(empty($source)) {
                $source = $device->read($path);
            }
            $compressor = new GZIP();
            $source = $compressor->decompress($source);
        }

        if(!empty($source)) {
            if(!empty($rangeHeader)) {
                $response->send(substr($source, $rangeStart, ($rangeEnd - $rangeStart + 1)));
            }
            $response->send($source);
        }

        if(!empty($rangeHeader)) {
            $response->send($device->read($path, $rangeStart, ($rangeEnd - $rangeStart + 1)));
        }

        if ($size > APP_STORAGE_READ_BUFFER) {          
            $response->addHeader('Content-Length', $device->getFileSize($path));
            $chunk = 2000000; // Max chunk of 2 mb
            for ($i=0; $i < ceil($size / $chunk); $i++) {
                $response->chunk($device->read($path, ($i * $chunk), min($chunk, $size - ($i * $chunk))), (($i + 1) * $chunk) >= $size);
            }
        } else {
            $response->send($device->read($path));
        }
    });

App::get('/v1/storage/buckets/:bucketId/files/:fileId/view')
    ->alias('/v1/storage/files/:fileId/view', ['bucketId' => 'default'])
    ->desc('Get File for View')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileView')
    ->label('sdk.description', '/docs/references/storage/get-file-view.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('request')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $response, $request, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Swoole\Request $request */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file  = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);
        $mimes = Config::getParam('storage-mimes');

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path', '');

        if (!\file_exists($path)) {
            throw new Exception('File not found in ' . $path, 404);
        }

        $compressor = new GZIP();
        $device = Storage::getDevice('files');

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
        if(!empty($rangeHeader)) {  
            list($unit, $range) = explode('=', $rangeHeader);
            if($unit == 'bytes' && !empty($range)) {
                list($rangeStart, $rangeEnd) = explode('-', $range);
                if(strlen($rangeStart) == 0 || strstr($range, '-') === false) {
                    throw new Exception('Invalid range', 416);
                }
                $rangeStart = (int) $rangeStart;
                if(strlen($rangeEnd) == 0) {
                    $rangeEnd =  min(($rangeStart + 2000000-1), ($size - 1));
                } else {
                    $rangeEnd = (int) $rangeEnd;
                }
                if(($rangeStart >= $rangeEnd) || $rangeEnd >= $size) {
                    throw new Exception('Invalid range', 416);
                }
                
                $response
                    ->addHeader('Accept-Ranges', 'bytes')
                    ->addHeader('Content-Range', 'bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $size)
                    ->addHeader('Content-Length', $rangeEnd - $rangeStart + 1)
                    ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);
            } else {
                throw new Exception('Invalid range', 416);
            }
        }

        $source = '';
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = $device->read($path);
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        if (!empty($file->getAttribute('algorithm', ''))) {
            if(empty($source)) {
                $source = $device->read($path);
            }
            $compressor = new GZIP();
            $source = $compressor->decompress($source);
        }

        $usage
            ->setParam('storage.files.read', 1)
            ->setParam('bucketId', $bucketId)
        ;

        if(!empty($source)) {
            if(!empty($rangeHeader)) {
                $response->send(substr($source, $rangeStart, ($rangeEnd - $rangeStart + 1)));
            }
            $response->send($source);
        }

        if(!empty($rangeHeader)) {
            $response->send($device->read($path, $rangeStart, ($rangeEnd - $rangeStart + 1)));
        }

        $size = $device->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {          
            $response->addHeader('Content-Length', $device->getFileSize($path));
            $chunk = 2000000; // Max chunk of 2 mb
            for ($i=0; $i < ceil($size / $chunk); $i++) {
                $response->chunk($device->read($path, ($i * $chunk), min($chunk, $size - ($i * $chunk))), (($i + 1) * $chunk) >= $size);
            }
        } else {
            $response->send($device->read($path));
        }
    });

App::put('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Update File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('event', 'storage.files.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'updateFile')
    ->label('sdk.description', '/docs/references/storage/update-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $read, $write, $response, $dbForInternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $file = $dbForInternal->updateDocument('bucket_' . $bucketId, $fileId, $file
                ->setAttribute('$read', $read)
                ->setAttribute('$write', $write)
        );

        $audits
            ->setParam('event', 'storage.files.update')
            ->setParam('resource', 'file/'.$file->getId())
        ;

        $usage
            ->setParam('storage.files.update', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $response->dynamic($file, Response::MODEL_FILE);
    });

App::delete('/v1/storage/buckets/:bucketId/files/:fileId')
    ->alias('/v1/storage/files/:fileId', ['bucketId' => 'default'])
    ->desc('Delete File')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
    ->label('event', 'storage.files.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'deleteFile')
    ->label('sdk.description', '/docs/references/storage/delete-file.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $fileId, $response, $dbForInternal, $events, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */
        
        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('bucket_' . $bucketId, $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $device = Storage::getDevice('files');

        if ($device->delete($file->getAttribute('path', ''))) {
            if (!$dbForInternal->deleteDocument('bucket_' . $bucketId, $fileId)) {
                throw new Exception('Failed to remove file from DB', 500);
            }
        } else {
            throw new Exception('Failed to delete file from device', 500);
        }

        $audits
            ->setParam('event', 'storage.files.delete')
            ->setParam('resource', 'file/'.$file->getId())
        ;

        $usage
            ->setParam('storage', $file->getAttribute('size', 0) * -1)
            ->setParam('storage.files.delete', 1)
            ->setParam('bucketId', $bucketId)
        ;

        $events
            ->setParam('eventData', $response->output($file, Response::MODEL_FILE))
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
    ->inject('dbForInternal')
    ->action(function ($range, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
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
                "storage.total",
                "storage.files.count"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $period, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period[$range]['period']]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $period[$range]['limit'], 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'storage' => $stats['storage.total'],
                'files' => $stats['storage.files.count']
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
    ->param('bucketId', '', new UID(), 'Bucket unique ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($bucketId, $range, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        } 
        
        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
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
                "storage.buckets.$bucketId.files.count",
                "storage.buckets.$bucketId.files.create",
                "storage.buckets.$bucketId.files.read",
                "storage.buckets.$bucketId.files.update",
                "storage.buckets.$bucketId.files.delete"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $period, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period[$range]['period']]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $period[$range]['limit'], 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'files.count' => $stats["storage.buckets.$bucketId.files.count"],
                'files.create' => $stats["storage.buckets.$bucketId.files.create"],
                'files.read' => $stats["storage.buckets.$bucketId.files.read"],
                'files.update' => $stats["storage.buckets.$bucketId.files.update"],
                'files.delete' => $stats["storage.buckets.$bucketId.files.delete"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_BUCKETS);
    });
