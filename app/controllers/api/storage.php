<?php

use Appwrite\ClamAV\Network;
use Appwrite\Database\Validator\UID;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Exception;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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
    ->param('name', '', new Text(128), 'Bucket name', false)
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('maximumFileSize', (int) App::getEnv('_APP_STORAGE_LIMIT', 0), new Integer(), 'Maximum file size allowed in bytes. Maximum allowed value is ' . App::getEnv('_APP_STORAGE_LIMIT', 0) . '. For self-hosted setups you can change the max limit by changing the `_APP_STORAGE_LIMIT` environment variable. [Learn more about storage environment variables](docs/environment-variables#storage)', true)
    ->param('allowedFileExtensions', [], new ArrayList(new Text(64)), 'Allowed file extensions', true)
    ->param('enabled', true, new Boolean(), 'Is bucket enabled?', true)
    ->param('adapter', 'local', new WhiteList(['local']), 'Storage adapter.', true)
    ->param('encryption', true, new Boolean(), 'Is encryption enabled? For file size above ' . Storage::human(APP_LIMIT_ENCRYPTION) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antiVirus', true, new Boolean(), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($name, $read, $write, $maximumFileSize, $allowedFileExtensions, $enabled, $adapter, $encryption, $antiVirus, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $data = $dbForInternal->createDocument('buckets', new Document([
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

        $audits
            ->setParam('event', 'storage.buckets.create')
            ->setParam('resource', 'storage/buckets/' . $data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($data, Response::MODEL_BUCKET);
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
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($search, $limit, $offset, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $queries = ($search) ? [new Query('name', Query::TYPE_SEARCH, $search)] : [];

        $response->dynamic2(new Document([
            'buckets' => $dbForInternal->find('buckets', $queries, $limit, $offset, ['_id'], [$orderType]),
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
    ->action(function ($bucketId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $response->dynamic2($bucket, Response::MODEL_BUCKET);
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
    ->param('encryption', true, new Boolean(), 'Is encryption enabled? For file size above ' . Storage::human(APP_LIMIT_ENCRYPTION) . ' encryption is skipped even if it\'s enabled', true)
    ->param('antiVirus', true, new Boolean(), 'Is virus scanning enabled? For file size above ' . Storage::human(APP_LIMIT_ANTIVIRUS) . ' AntiVirus scanning is skipped even if it\'s enabled', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($bucketId, $name, $read, $write, $maximumFileSize, $allowedFileExtensions, $enabled, $encryption, $antiVirus, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $read??=$bucket->getAttribute('$read', []); // By default inherit read permissions
        $write??=$bucket->getAttribute('$write', []); // By default inherit write permissions

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

        $response->dynamic2($bucket, Response::MODEL_BUCKET);
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
    ->action(function ($bucketId, $response, $dbForInternal, $audits, $deletes, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $deletes */
        /** @var Appwrite\Event\Event $events */

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
            ->setParam('eventData', $response->output2($bucket, Response::MODEL_BUCKET))
        ;

        $audits
            ->setParam('event', 'storage.buckets.delete')
            ->setParam('resource', 'storage/buckets/' . $bucket->getId())
            ->setParam('data', $bucket->getArrayCopy())
        ;

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
    ->param('file', [], new File(), 'Binary file.', false)
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($bucketId, $file, $read, $write, $request, $response, $dbForInternal, $user, $audits, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $request->getFiles('file');

        /*
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
        $uploadId = \uniqid();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $uploadId = empty($request->getHeader('x-appwrite-upload-id')) ? $uploadId : $request->getHeader('x-appwrite-upload-id');
            $contentRange = explode(" ", $contentRange);
            if (count($contentRange) != 2) {
                throw new Exception('Invalid content-range header', 400);
            }

            $rangeData = explode("/", $contentRange[1]);
            if (count($rangeData) != 2) {
                throw new Exception('Invalid content-range header', 400);
            }

            $size = (int) $rangeData[1];
            $parts = explode("-", $rangeData[0]);
            if (count($parts) != 2) {
                throw new Exception('Invalid content-range header', 400);
            }

            $start = (int) $parts[0];
            $end = (int) $parts[1];
            if ($start > $end || $end > $size) {
                throw new Exception('Invalid content-range header', 400);
            }

            if ($end == $size) {
                $chunks = $chunk = -1;
            } else {
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
        $size = $size ?? $device->getFileSize($fileTmpName);
        $path = $device->getPath($uploadId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $path = $bucket->getId() . $path;

        $file = $dbForInternal->getDocument('files', $uploadId);

        if (!$file->isEmpty()) {
            $chunks = $file->getAttribute('totalChunks', 1);
            if ($chunk == -1) {
                $chunk = $chunks - 1;
            }
        }

        $uploadedChunks = $device->upload($fileTmpName, $path, $chunk, $chunks);
        if (empty($uploadedChunks)) {
            throw new Exception('Failed uploading file', 500);
        }

        if ($uploadedChunks == $chunks) {
            $mimeType = $device->getFileMimeType($path); // Get mime-type before compression and encryption

            if (App::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled' && $bucket->getAttribute('antiVirus', true) && $size <= APP_LIMIT_ANTIVIRUS) {
                $antiVirus = new Network(App::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                    (int) App::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310));

                if (!$antiVirus->fileScan($path)) {
                    $device->delete($path);
                    throw new Exception('Invalid file', 403);
                }
            }

            // Compression
            $data = $device->read($path);
            if ($size <= APP_LIMIT_COMPRESSION) {
                $compressor = new GZIP();
                $data = $compressor->compress($data);
            }

            if ($bucket->getAttribute('encryption', true) && $size <= APP_LIMIT_ENCRYPTION) {
                $key = App::getEnv('_APP_OPENSSL_KEY_V1');
                $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
                $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);
            }

            if (!$device->write($path, $data, $mimeType)) {
                throw new Exception('Failed to save file', 500);
            }

            $sizeActual = $device->getFileSize($path);

            $read = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? [];
            $write = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [];
            $algorithm = empty($compressor) ? '' : $compressor->getName();
            $fileHash = $device->getFileHash($path);

            if ($bucket->getAttribute('encryption', true) && $size <= APP_LIMIT_ENCRYPTION) {
                $openSSLVersion = '1';
                $openSSLCipher = OpenSSL::CIPHER_AES_128_GCM;
                $openSSLTag = \bin2hex($tag);
                $openSSLIV = \bin2hex($iv);
            }

            if ($file->isEmpty()) {
                $data = [
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
                    'totalChunks' => $chunks,
                    'uploadedChunks' => $uploadedChunks,
                    'openSSLVersion' => $openSSLVersion,
                    'openSSLCipher' => $openSSLCipher,
                    'openSSLTag' => $openSSLTag,
                    'openSSLIV' => $openSSLIV,
                ];
                $file = $dbForInternal->createDocument('files', new Document($data));
            } else {
                $file = $dbForInternal->updateDocument('files', $uploadId, $file
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
                $data = [
                    '$id' => $uploadId,
                    '$read' => (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? [], // By default set read permissions for user
                    '$write' => (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [], // By default set write permissions for user
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
                    'totalChunks' => $chunks,
                    'uploadedChunks' => $uploadedChunks,
                ];
                $file = $dbForInternal->createDocument('files', new Document($data));
            } else {
                $file = $dbForInternal->updateDocument('files', $uploadId, $file
                        ->setAttribute('uploadedChunks', $uploadedChunks)
                );
            }
        }

        $audits
            ->setParam('event', 'storage.files.create')
            ->setParam('resource', 'storage/files/' . $file->getId())
        ;

        if (!empty($sizeActual)) {
            $usage
                ->setParam('storage', $sizeActual)
            ;
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($file, Response::MODEL_FILE);
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
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($bucketId, $search, $limit, $offset, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $queries = [new Query('bucketId', Query::TYPE_EQUAL, [$bucketId])];

        if ($search) {
            $queries[] = [new Query('name', Query::TYPE_SEARCH, [$search])];
        }

        $response->dynamic2(new Document([
            'files' => $dbForInternal->find('files', $queries, $limit, $offset, ['_id'], [$orderType]),
            'sum' => $dbForInternal->count('files', $queries, APP_LIMIT_COUNT),
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
    ->action(function ($bucketId, $fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('files', $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $response->dynamic2($file, Response::MODEL_FILE);
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
    ->action(function ($bucketId, $fileId, $width, $height, $gravity, $quality, $borderWidth, $borderColor, $borderRadius, $opacity, $rotation, $background, $output, $request, $response, $project, $dbForInternal) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */

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

        $file = $dbForInternal->getDocument('files', $fileId);

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
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($bucketId, $fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('files', $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path', '');

        if (!\file_exists($path)) {
            throw new Exception('File not found in ' . $path, 404);
        }

        $device = Storage::getDevice('files');

        $source = $device->read($path);
        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
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
            $compressor = new GZIP();
            $source = $compressor->decompress($source);
        }

        // Response
        $response
            ->setContentType($file->getAttribute('mimeType'))
            ->addHeader('Content-Disposition', 'attachment; filename="' . $file->getAttribute('name', '') . '"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->send($source)
        ;
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
    ->inject('dbForInternal')
    ->action(function ($bucketId, $fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('files', $fileId);
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

        $source = $device->read($path);

        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        $output = $compressor->decompress($source);
        $fileName = $file->getAttribute('name', '');

        // Response
        $response
            ->setContentType($contentType)
            ->addHeader('Content-Security-Policy', 'script-src none;')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Content-Disposition', 'inline; filename="' . $fileName . '"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->send($output)
        ;
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
    ->action(function ($bucketId, $fileId, $read, $write, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('files', $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $file = $dbForInternal->updateDocument('files', $fileId, $file
                ->setAttribute('$read', $read)
                ->setAttribute('$write', $write)
        );

        $audits
            ->setParam('event', 'storage.files.update')
            ->setParam('resource', 'storage/files/' . $file->getId())
        ;

        $response->dynamic2($file, Response::MODEL_FILE);
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
        /** @var Appwrite\Event\Event $usage */

        $bucket = $dbForInternal->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new Exception('Bucket not found', 404);
        }

        $file = $dbForInternal->getDocument('files', $fileId);

        if ($file->isEmpty() || $file->getAttribute('bucketId') != $bucketId) {
            throw new Exception('File not found', 404);
        }

        $device = Storage::getDevice('files');

        if ($device->delete($file->getAttribute('path', ''))) {
            if (!$dbForInternal->deleteDocument('files', $fileId)) {
                throw new Exception('Failed to remove file from DB', 500);
            }
        }

        $audits
            ->setParam('event', 'storage.files.delete')
            ->setParam('resource', 'storage/files/' . $file->getId())
        ;

        $usage
            ->setParam('storage', $file->getAttribute('size', 0) * -1)
        ;

        $events
            ->setParam('eventData', $response->output2($file, Response::MODEL_FILE))
        ;

        $response->noContent();
    });
