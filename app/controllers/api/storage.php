<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Appwrite\ClamAV\Network;
use Utopia\Database\Document;
use Appwrite\Database\Validator\UID;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Image\Image;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Validator\Integer;
use Appwrite\Database\Exception\Authorization as AuthorizationException;
use Appwrite\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;

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
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('maximumFileSize', 0, new Integer(), 'Maximum file size allowed.', true)
    ->param('allowedFileExtensions', ['*'], new ArrayList(new Text(64)), 'Allowed file extensions', true)
    ->param('enabled', true, new Boolean(), 'Is bucket enabled?', true)
    ->param('adapter', 'local', new WhiteList(['local']), 'Storage adapter.', true)
    ->param('encryption', true, new Boolean(), 'Is encryption enabled?', true)
    ->param('antiVirus', true, new Boolean(), 'Is virus scanning enabled?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('user')
    ->inject('audits')
    ->action(function ($name, $read, $write, $maximumFileSize, $allowedFileExtensions, $enabled, $adapter, $encryption, $antiVirus, $response, $dbForInternal, $user, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */

        $data = [
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
        ];

        $data['$read'] = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? []; //  By default set read permissions for user
        $data['$write'] = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? []; //  By default set write permissions for user
        $data = $dbForInternal->createDocument('buckets', new Document($data));

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'database/collection/' . $data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($data, Response::MODEL_BUCKET);
    });

App::get('/v1/storage/buckets')
    ->desc('List buckets')
    ->groups(['api', 'storage'])
    ->label('scope', 'buckets.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
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
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
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

        if (empty($bucket->getId())) {
            throw new Exception('Bucket not found', 404);
        }

        $response->dynamic2($bucket, Response::MODEL_BUCKET);
    });


App::post('/v1/storage/files')
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
    ->param('file', [], new File(), 'Binary file.', false)
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($file, $read, $write, $request, $response, $dbForInternal, $user, $audits, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $usage */

        $file = $request->getFiles('file');

        /*
         * Validators
         */
        //$fileType = new FileType(array(FileType::FILE_TYPE_PNG, FileType::FILE_TYPE_GIF, FileType::FILE_TYPE_JPEG));
        $fileSize = new FileSize(App::getEnv('_APP_STORAGE_LIMIT', 0));
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400);
        }

        // Make sure we handle a single file and multiple files the same way
        $file['name'] = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $file['tmp_name'] = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $file['size'] = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        // Check if file type is allowed (feature for project settings?)
        //if (!$fileType->isValid($file['tmp_name'])) {
        //throw new Exception('File type not allowed', 400);
        //}

        if (!$fileSize->isValid($file['size'])) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400);
        }

        $device = Storage::getDevice('files');

        if (!$upload->isValid($file['tmp_name'])) {
            throw new Exception('Invalid file', 403);
        }

        // Save to storage
        $size = $device->getFileSize($file['tmp_name']);
        $path = $device->getPath(\uniqid().'.'.\pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!$device->upload($file['tmp_name'], $path)) { // TODO deprecate 'upload' and replace with 'move'
            throw new Exception('Failed moving file', 500);
        }

        $mimeType = $device->getFileMimeType($path); // Get mime-type before compression and encryption

        if (App::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled') { // Check if scans are enabled
            $antiVirus = new Network(App::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                (int) App::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310));

            if (!$antiVirus->fileScan($path)) {
                $device->delete($path);
                throw new Exception('Invalid file', 403);
            }
        }

        // Compression
        $compressor = new GZIP();
        $data = $device->read($path);
        $data = $compressor->compress($data);
        $key = App::getEnv('_APP_OPENSSL_KEY_V1');
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);

        if (!$device->write($path, $data, $mimeType)) {
            throw new Exception('Failed to save file', 500);
        }

        $sizeActual = $device->getFileSize($path);
        
        $file = $dbForInternal->createDocument('files', new Document([
            '$read' => (is_null($read) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $read ?? [], // By default set read permissions for user
            '$write' => (is_null($write) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $write ?? [], // By default set write permissions for user
            'dateCreated' => \time(),
            'bucketId' => '',
            'name' => $file['name'],
            'path' => $path,
            'signature' => $device->getFileHash($path),
            'mimeType' => $mimeType,
            'sizeOriginal' => $size,
            'sizeActual' => $sizeActual,
            'algorithm' => $compressor->getName(),
            'comment' => '',
            'openSSLVersion' => '1',
            'openSSLCipher' => OpenSSL::CIPHER_AES_128_GCM,
            'openSSLTag' => \bin2hex($tag),
            'openSSLIV' => \bin2hex($iv),
        ]));

        $audits
            ->setParam('event', 'storage.files.create')
            ->setParam('resource', 'storage/files/'.$file->getId())
        ;

        $usage
            ->setParam('storage', $sizeActual)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($file, Response::MODEL_FILE);
        ;
    });

App::get('/v1/storage/files')
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
            'files' => $dbForInternal->find('files', $queries, $limit, $offset, ['_id'], [$orderType]),
            'sum' => $dbForInternal->count('files', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FILE_LIST);
    });

App::get('/v1/storage/files/:fileId')
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
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $file = $dbForInternal->getDocument('files', $fileId);

        if (empty($file->getId())) {
            throw new Exception('File not found', 404);
        }

        $response->dynamic2($file, Response::MODEL_FILE);
    });

App::get('/v1/storage/files/:fileId/preview')
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
    ->param('fileId', '', new UID(), 'File unique ID')
    ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
    ->param('gravity', Image::GRAVITY_CENTER, new WhiteList([Image::GRAVITY_CENTER, Image::GRAVITY_NORTH, Image::GRAVITY_NORTHWEST, Image::GRAVITY_NORTHEAST, Image::GRAVITY_WEST, Image::GRAVITY_EAST, Image::GRAVITY_SOUTHWEST, Image::GRAVITY_SOUTH, Image::GRAVITY_SOUTHEAST]), 'Image crop gravity', true)
    ->param('quality', 100, new Range(0, 100), 'Preview image quality. Pass an integer between 0 to 100. Defaults to 100.', true)
    ->param('borderWidth', 0, new Range(0, 100), 'Preview image border in pixels. Pass an integer between 0 to 100. Defaults to 0.', true)
    ->param('borderColor', '', new HexColor(), 'Preview image border color. Use a valid HEX color, no # is needed for prefix.', true)
    ->param('borderRadius', 0, new Range(0, 4000), 'Preview image border radius in pixels. Pass an integer between 0 to 4000.', true)
    ->param('opacity', 1, new Range(0,1, Range::TYPE_FLOAT), 'Preview image opacity. Only works with images having an alpha channel (like png). Pass a number between 0 to 1.', true)
    ->param('rotation', 0, new Range(0,360), 'Preview image rotation in degrees. Pass an integer between 0 and 360.', true)
    ->param('background', '', new HexColor(), 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
    ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForInternal')
    ->action(function ($fileId, $width, $height, $gravity, $quality, $borderWidth, $borderColor, $borderRadius, $opacity, $rotation, $background, $output, $request, $response, $project, $dbForInternal) {
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

        if ((\strpos($request->getAccept(), 'image/webp') === false) && ('webp' == $output)) { // Fallback webp to jpeg when no browser support
            $output = 'jpg';
        }

        $inputs = Config::getParam('storage-inputs');
        $outputs = Config::getParam('storage-outputs');
        $fileLogos = Config::getParam('storage-logos');

        $date = \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)).' GMT';  // 45 days cache
        $key = \md5($fileId.$width.$height.$quality.$borderWidth.$borderColor.$borderRadius.$opacity.$rotation.$background.$storage.$output);

        $file = $dbForInternal->getDocument('files', $fileId);

        if (empty($file->getId())) {
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
            $key = \md5($path.$width.$height.$quality.$borderWidth.$borderColor.$borderRadius.$opacity.$rotation.$background.$storage.$output);
        }

        $compressor = new GZIP();
        $device = Storage::getDevice('files');

        if (!\file_exists($path)) {
            throw new Exception('File not found', 404);
        }

        $cache = new Cache(new Filesystem(APP_STORAGE_CACHE.'/app-'.$project->getId())); // Limit file number or size
        $data = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

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
                App::getEnv('_APP_OPENSSL_KEY_V'.$file->getAttribute('openSSLVersion')),
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
        
        if (!empty($opacity) || $opacity==0) {
            $image->setOpacity($opacity);
        }

        if (!empty($background)) {
            $image->setBackground('#'.$background);
        }

        
        if (!empty($borderWidth) ) {
            $image->setBorder($borderWidth, '#'.$borderColor);
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

App::get('/v1/storage/files/:fileId/download')
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
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $file = $dbForInternal->getDocument('files', $fileId);

        if (empty($file->getId())) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path', '');

        if (!\file_exists($path)) {
            throw new Exception('File not found in '.$path, 404);
        }

        $compressor = new GZIP();
        $device = Storage::getDevice('files');

        $source = $device->read($path);

        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $source = OpenSSL::decrypt(
                $source,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V'.$file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        $source = $compressor->decompress($source);

        // Response
        $response
            ->setContentType($file->getAttribute('mimeType'))
            ->addHeader('Content-Disposition', 'attachment; filename="'.$file->getAttribute('name', '').'"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->send($source)
        ;
    });

App::get('/v1/storage/files/:fileId/view')
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
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($fileId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $file  = $dbForInternal->getDocument('files', $fileId);
        $mimes = Config::getParam('storage-mimes');

        if (empty($file->getId())) {
            throw new Exception('File not found', 404);
        }

        $path = $file->getAttribute('path', '');

        if (!\file_exists($path)) {
            throw new Exception('File not found in '.$path, 404);
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
                App::getEnv('_APP_OPENSSL_KEY_V'.$file->getAttribute('openSSLVersion')),
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
            ->addHeader('Content-Disposition', 'inline; filename="'.$fileName.'"')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->send($output)
        ;
    });

App::put('/v1/storage/files/:fileId')
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
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($fileId, $read, $write, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $file = $dbForInternal->getDocument('files', $fileId);

        if (empty($file->getId())) {
            throw new Exception('File not found', 404);
        }

        $file = $dbForInternal->updateDocument('files', $fileId, new Document(\array_merge($file->getArrayCopy(), [
            '$read' => $read,
            '$write' => $write,
            'bucketId' => '',
        ])));

        $audits
            ->setParam('event', 'storage.files.update')
            ->setParam('resource', 'storage/files/'.$file->getId())
        ;

        $response->dynamic2($file, Response::MODEL_FILE);
    });

App::delete('/v1/storage/files/:fileId')
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
    ->param('fileId', '', new UID(), 'File unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($fileId, $response, $dbForInternal, $events, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $usage */
        
        $file = $dbForInternal->getDocument('files', $fileId);

        if (empty($file->getId())) {
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
            ->setParam('resource', 'storage/files/'.$file->getId())
        ;

        $usage
            ->setParam('storage', $file->getAttribute('size', 0) * -1)
        ;

        $events
            ->setParam('eventData', $response->output2($file, Response::MODEL_FILE))
        ;

        $response->noContent();
    });
