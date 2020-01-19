<?php

global $utopia, $request, $response, $register, $user, $audit, $usage, $project, $projectDB;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\HexColor;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;
use Appwrite\ClamAV\Network;
use Database\Database;
use Database\Validator\UID;
use Storage\Storage;
use Storage\Devices\Local;
use Storage\Validators\File;
use Storage\Validators\FileSize;
use Storage\Validators\Upload;
use Storage\Compression\Algorithms\GZIP;
use Resize\Resize;
use OpenSSL\OpenSSL;

include_once __DIR__ . '/../shared/api.php';

Storage::addDevice('local', new Local('/storage/uploads/app-'.$project->getUid()));

$fileLogos = [ // Based on this list @see http://stackoverflow.com/a/4212908/2299554
    'default' => 'default.gif',

    // Microsoft Word
    'application/msword' => 'word.gif',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word.gif',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => 'word.gif',
    'application/vnd.ms-word.document.macroEnabled.12' => 'word.gif',

    // Microsoft Excel
    'application/vnd.ms-excel' => 'excel.gif',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel.gif',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.template' => 'excel.gif',
    'application/vnd.ms-excel.sheet.macroEnabled.12' => 'excel.gif',
    'application/vnd.ms-excel.template.macroEnabled.12' => 'excel.gif',
    'application/vnd.ms-excel.addin.macroEnabled.12' => 'excel.gif',
    'application/vnd.ms-excel.sheet.binary.macroEnabled.12' => 'excel.gif',

    // Microsoft Power Point
    'application/vnd.ms-powerpoint' => 'powerpoint.gif',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint.gif',
    'application/vnd.openxmlformats-officedocument.presentationml.template' => 'powerpoint.gif',
    'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => 'powerpoint.gif',
    'application/vnd.ms-powerpoint.addin.macroEnabled.12' => 'powerpoint.gif',
    'application/vnd.ms-powerpoint.presentation.macroEnabled.12' => 'powerpoint.gif',
    'application/vnd.ms-powerpoint.template.macroEnabled.12' => 'powerpoint.gif',
    'application/vnd.ms-powerpoint.slideshow.macroEnabled.12' => 'powerpoint.gif',

    // Microsoft Access
    'application/vnd.ms-access' => 'access.gif',

    // Adobe PDF
    'application/pdf' => 'pdf.gif',
];

$inputs = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'png' => 'image/png',
];

$outputs = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

$mimes = [
    'image/jpeg',
    'image/jpeg',
    'image/gif',
    'image/png',
    'image/webp',

    // Microsoft Word
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
    'application/vnd.ms-word.document.macroEnabled.12',

    // Microsoft Excel
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
    'application/vnd.ms-excel.sheet.macroEnabled.12',
    'application/vnd.ms-excel.template.macroEnabled.12',
    'application/vnd.ms-excel.addin.macroEnabled.12',
    'application/vnd.ms-excel.sheet.binary.macroEnabled.12',

    // Microsoft Power Point
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.openxmlformats-officedocument.presentationml.template',
    'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
    'application/vnd.ms-powerpoint.addin.macroEnabled.12',
    'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
    'application/vnd.ms-powerpoint.template.macroEnabled.12',
    'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',

    // Microsoft Access
    'application/vnd.ms-access',

    // Adobe PDF
    'application/pdf',
];

$utopia->get('/v1/storage/files')
    ->desc('List Files')
    ->label('scope', 'files.read')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'listFiles')
    ->label('sdk.description', '/docs/references/storage/list-files.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($search, $limit, $offset, $orderType) use ($response, $projectDB) {
            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'dateCreated',
                'orderType' => $orderType,
                'orderCast' => 'int',
                'search' => $search,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_FILES,
                ],
            ]);

            $results = array_map(function ($value) { /* @var $value \Database\Document */
                return $value->getArrayCopy(['$uid', '$permissions', 'name', 'dateCreated', 'signature', 'mimeType', 'sizeOriginal']);
            }, $results);

            $response->json(['sum' => $projectDB->getSum(), 'files' => $results]);
        }
    );

$utopia->get('/v1/storage/files/:fileId')
    ->desc('Get File')
    ->label('scope', 'files.read')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFile')
    ->label('sdk.description', '/docs/references/storage/get-file.md')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->action(
        function ($fileId) use ($response, $projectDB) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $response->json($file->getArrayCopy(['$uid', '$permissions', 'name', 'dateCreated', 'signature', 'mimeType', 'sizeOriginal']));
        }
    );

$utopia->get('/v1/storage/files/:fileId/preview')
    ->desc('Get File Preview')
    ->label('scope', 'files.read')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFilePreview')
    ->label('sdk.description', '/docs/references/storage/get-file-preview.md')
    ->label('sdk.response.type', 'image/*')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID')
    ->param('width', 0, function () { return new Range(0, 4000); }, 'Resize preview image width, Pass an integer between 0 to 4000', true)
    ->param('height', 0, function () { return new Range(0, 4000); }, 'Resize preview image height, Pass an integer between 0 to 4000', true)
    ->param('quality', 100, function () { return new Range(0, 100); }, 'Preview image quality. Pass an integer between 0 to 100. Defaults to 100', true)
    ->param('background', '', function () { return new HexColor(); }, 'Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix.', true)
    ->param('output', null, function () use ($outputs) { return new WhiteList(array_merge(array_keys($outputs), [null])); }, 'Output format type (jpeg, jpg, png, gif and webp)', true)
    //->param('storage', 'local', function () {return new WhiteList(array('local'));}, 'Selected storage device. defaults to local')
    //->param('token', '', function () {return new Text(128);}, 'Preview token', true)
    ->action(
        function ($fileId, $width, $height, $quality, $background, $output) use ($request, $response, $projectDB, $project, $inputs, $outputs, $fileLogos) {
            $storage = 'local';

            if (!extension_loaded('imagick')) {
                throw new Exception('Imagick extension is missing', 500);
            }

            if (!Storage::exists($storage)) {
                throw new Exception('No such storage device', 400);
            }

            if ((strpos($request->getServer('HTTP_ACCEPT'), 'image/webp') === false) && ('webp' == $output)) { // Fallback webp to jpeg when no browser support
                $output = 'jpg';
            }

            $date = date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT';  // 45 days cache
            $key = md5($fileId.$width.$height.$quality.$background.$storage.$output);

            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $path = $file->getAttribute('path');
            $algorithm = $file->getAttribute('algorithm');
            $type = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $cipher = $file->getAttribute('fileOpenSSLCipher');

            $compressor = new GZIP();
            $device = Storage::getDevice('local');

            if (!file_exists($path)) {
                throw new Exception('File not found in '.$path, 404);
            }

            $cache = new Cache(new Filesystem('/storage/cache/app-'.$project->getUid())); // Limit file number or size
            $data = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

            if ($data) {
                $output = (empty($output)) ? $type : $output;

                $response
                    ->setContentType((in_array($output, $outputs)) ? $outputs[$output] : $outputs['jpg'])
                    ->addHeader('Expires', $date)
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->send($data, 0)
                ;
            }

            $source = $device->read($path);

            if (!empty($cipher)) { // Decrypt
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('fileOpenSSLCipher'),
                    $request->getServer('_APP_OPENSSL_KEY_V'.$file->getAttribute('fileOpenSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('fileOpenSSLIV')),
                    hex2bin($file->getAttribute('fileOpenSSLTag'))
                );
            }

            if (!empty($algorithm)) {
                $source = $compressor->decompress($source);
            }

            $resize = new Resize($source);

            $resize->crop((int) $width, (int) $height);

            if (!empty($background)) {
                $resize->setBackground('#'.$background);
            }

            $output = (empty($output)) ? $type : $output;

            $response
                ->setContentType($outputs[$output])
                ->addHeader('Expires', $date)
                ->addHeader('X-Appwrite-Cache', 'miss')
                ->send('', null)
            ;

            $data = $resize->output($output, $quality);

            $cache->save($key, $data);

            echo $data;

            unset($resize);

            exit(0);
        }
    );

$utopia->get('/v1/storage/files/:fileId/download')
    ->desc('Get File for Download')
    ->label('scope', 'files.read')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileDownload')
    ->label('sdk.description', '/docs/references/storage/get-file-download.md')
    ->label('sdk.response.type', '*')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->action(
        function ($fileId) use ($response, $request, $projectDB) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $path = $file->getAttribute('path', '');

            if (!file_exists($path)) {
                throw new Exception('File not found in '.$path, 404);
            }

            $compressor = new GZIP();
            $device = Storage::getDevice('local');

            $source = $device->read($path);

            if (!empty($file->getAttribute('fileOpenSSLCipher'))) { // Decrypt
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('fileOpenSSLCipher'),
                    $request->getServer('_APP_OPENSSL_KEY_V'.$file->getAttribute('fileOpenSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('fileOpenSSLIV')),
                    hex2bin($file->getAttribute('fileOpenSSLTag'))
                );
            }

            $source = $compressor->decompress($source);

            // Response
            $response
                ->setContentType($file->getAttribute('mimeType'))
                ->addHeader('Content-Disposition', 'attachment; filename="'.$file->getAttribute('name', '').'"')
                ->addHeader('Expires', date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
                ->addHeader('X-Peak', memory_get_peak_usage())
                ->send($source)
            ;
        }
    );

$utopia->get('/v1/storage/files/:fileId/view')
    ->desc('Get File for View')
    ->label('scope', 'files.read')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileView')
    ->label('sdk.description', '/docs/references/storage/get-file-view.md')
    ->label('sdk.response.type', '*')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->param('as', '', function () { return new WhiteList(['pdf', /*'html',*/ 'text']); }, 'Choose a file format to convert your file to. Currently you can only convert word and pdf files to pdf or txt. This option is currently experimental only, use at your own risk.', true)
    ->action(
        function ($fileId, $as) use ($response, $request, $projectDB, $mimes) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $path = $file->getAttribute('path', '');

            if (!file_exists($path)) {
                throw new Exception('File not found in '.$path, 404);
            }

            $compressor = new GZIP();
            $device = Storage::getDevice('local');

            $contentType = 'text/plain';

            if (in_array($file->getAttribute('mimeType'), $mimes)) {
                $contentType = $file->getAttribute('mimeType');
            }

            $source = $device->read($path);

            if (!empty($file->getAttribute('fileOpenSSLCipher'))) { // Decrypt
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('fileOpenSSLCipher'),
                    $request->getServer('_APP_OPENSSL_KEY_V'.$file->getAttribute('fileOpenSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('fileOpenSSLIV')),
                    hex2bin($file->getAttribute('fileOpenSSLTag'))
                );
            }

            $output = $compressor->decompress($source);
            $fileName = $file->getAttribute('name', '');

            $contentTypes = [
                'pdf' => 'application/pdf',
                'text' => 'text/plain',
            ];

            $contentType = (array_key_exists($as, $contentTypes)) ? $contentTypes[$as] : $contentType;

            // Response
            $response
                ->setContentType($contentType)
                ->addHeader('Content-Security-Policy', 'script-src none;')
                ->addHeader('X-Content-Type-Options', 'nosniff')
                ->addHeader('Content-Disposition', 'inline; filename="'.$fileName.'"')
                ->addHeader('Expires', date('D, d M Y H:i:s', time() + (60 * 60 * 24 * 45)).' GMT') // 45 days cache
                ->addHeader('X-Peak', memory_get_peak_usage())
                ->send($output)
            ;
        }
    );

$utopia->post('/v1/storage/files')
    ->desc('Create File')
    ->label('scope', 'files.write')
    ->label('webhook', 'storage.files.create')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'createFile')
    ->label('sdk.description', '/docs/references/storage/create-file.md')
    ->label('sdk.consumes', 'multipart/form-data')
    ->param('file', [], function () { return new File(); }, 'Binary Files.', false)
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    // ->param('folderId', '', function () { return new UID(); }, 'Folder to associate files with.', true)
    ->action(
        function ($file, $read, $write, $folderId = '') use ($request, $response, $user, $projectDB, $webhook, $audit, $usage) {
            $file = $request->getFiles('file');
            $read = (empty($read)) ? ['user:'.$user->getUid()] : $read;
            $write = (empty($write)) ? ['user:'.$user->getUid()] : $write;

            /*
             * Validators
             */
            //$fileType = new FileType(array(FileType::FILE_TYPE_PNG, FileType::FILE_TYPE_GIF, FileType::FILE_TYPE_JPEG));
            $fileSize = new FileSize(2097152 * 2); // 4MB
            $upload = new Upload();

            if (empty($file)) {
                throw new Exception('No file sent', 400);
            }

            // Make sure we handle a single file and multiple files the same way
            $file['name'] = (is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
            $file['tmp_name'] = (is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
            $file['size'] = (is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

            // Check if file type is allowed (feature for project settings?)
            //if (!$fileType->isValid($file['tmp_name'])) {
            //throw new Exception('File type not allowed', 400);
            //}

            // Check if file size is exceeding allowed limit
            if (!$fileSize->isValid($file['size'])) {
                throw new Exception('File size not allowed', 400);
            }

            $antiVirus = new Network('clamav', 3310);

            /*
             * Models
             */
            $list = [];
            $device = Storage::getDevice('local');

            if (!$upload->isValid($file['tmp_name'])) {
                throw new Exception('Invalid file', 403);
            }

            // Save to storage
            $size = $device->getFileSize($file['tmp_name']);
            $path = $device->getPath(uniqid().'.'.pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!$device->upload($file['tmp_name'], $path)) { // TODO deprecate 'upload' and replace with 'move'
                throw new Exception('Failed moving file', 500);
            }

            $mimeType = $device->getFileMimeType($path); // Get mime-type before compression and encryption

            // Check if file size is exceeding allowed limit
            if (!$antiVirus->fileScan($path)) {
                $device->delete($path);
                throw new Exception('Invalid file', 403);
            }

            // Compression
            $compressor = new GZIP();
            $data = $device->read($path);
            $data = $compressor->compress($data);
            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $data = OpenSSL::encrypt($data, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag);

            if(!$device->write($path, $data)) {
                throw new Exception('Failed to save file', 500);
            }

            $sizeActual = $device->getFileSize($path);
            
            $file = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_FILES,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'dateCreated' => time(),
                'folderId' => $folderId,
                'name' => $file['name'],
                'path' => $path,
                'signature' => $device->getFileHash($path),
                'mimeType' => $mimeType,
                'sizeOriginal' => $size,
                'sizeActual' => $sizeActual,
                'algorithm' => $compressor->getName(),
                'token' => bin2hex(random_bytes(64)),
                'comment' => '',
                'fileOpenSSLVersion' => '1',
                'fileOpenSSLCipher' => OpenSSL::CIPHER_AES_128_GCM,
                'fileOpenSSLTag' => bin2hex($tag),
                'fileOpenSSLIV' => bin2hex($iv),
            ]);

            if (false === $file) {
                throw new Exception('Failed saving file to DB', 500);
            }

            $webhook
                ->setParam('payload', $file->getArrayCopy())
            ;

            $audit
                ->setParam('event', 'storage.files.create')
                ->setParam('resource', 'storage/files/'.$file->getUid())
            ;

            $usage
                ->setParam('storage', $sizeActual)
            ;

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($file->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/storage/files/:fileId')
    ->desc('Update File')
    ->label('scope', 'files.write')
    ->label('webhook', 'storage.files.update')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'updateFile')
    ->label('sdk.description', '/docs/references/storage/update-file.md')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    //->param('folderId', '', function () { return new UID(); }, 'Folder to associate files with.', true)
    ->action(
        function ($fileId, $read, $write, $folderId = '') use ($response, $projectDB, $audit, $webhook) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $file = $projectDB->updateDocument(array_merge($file->getArrayCopy(), [
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'folderId' => $folderId,
            ]));

            if (false === $file) {
                throw new Exception('Failed saving file to DB', 500);
            }

            $webhook
                ->setParam('payload', $file->getArrayCopy())
            ;

            $audit
                ->setParam('event', 'storage.files.update')
                ->setParam('resource', 'storage/files/'.$file->getUid())
            ;

            $response->json($file->getArrayCopy());
        }
    );

$utopia->delete('/v1/storage/files/:fileId')
    ->desc('Delete File')
    ->label('scope', 'files.write')
    ->label('webhook', 'storage.files.delete')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'deleteFile')
    ->label('sdk.description', '/docs/references/storage/delete-file.md')
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->action(
        function ($fileId) use ($response, $projectDB, $webhook, $audit, $usage) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $device = Storage::getDevice('local');

            if ($device->delete($file->getAttribute('path', ''))) {
                if (!$projectDB->deleteDocument($fileId)) {
                    throw new Exception('Failed to remove file from DB', 500);
                }
            }

            $webhook
                ->setParam('payload', $file->getArrayCopy())
            ;

            $audit
                ->setParam('event', 'storage.files.delete')
                ->setParam('resource', 'storage/files/'.$file->getUid())
            ;

            $usage
                ->setParam('storage', $file->getAttribute('size', 0) * -1)
            ;

            $response->noContent();
        }
    );

$utopia->get('/v1/storage/files/:fileId/scan')
    ->desc('Scan Storage')
    ->label('scope', 'god')
    ->label('sdk.namespace', 'storage')
    ->label('sdk.method', 'getFileScan')
    ->label('sdk.hide', true)
    ->param('fileId', '', function () { return new UID(); }, 'File unique ID.')
    ->param('storage', 'local', function () { return new WhiteList(['local']);
    })
    ->action(
        function ($fileId, $storage) use ($response, $request, $projectDB) {
            $file = $projectDB->getDocument($fileId);

            if (empty($file->getUid()) || Database::SYSTEM_COLLECTION_FILES != $file->getCollection()) {
                throw new Exception('File not found', 404);
            }

            $path = $file->getAttribute('path', '');

            if (!file_exists($path)) {
                throw new Exception('File not found in '.$path, 404);
            }

            $compressor = new GZIP();
            $device = Storage::getDevice($storage);

            $source = $device->read($path);

            if (!empty($file->getAttribute('fileOpenSSLCipher'))) { // Decrypt
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('fileOpenSSLCipher'),
                    $request->getServer('_APP_OPENSSL_KEY_V'.$file->getAttribute('fileOpenSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('fileOpenSSLIV')),
                    hex2bin($file->getAttribute('fileOpenSSLTag'))
                );
            }

            $source = $compressor->decompress($source);

            $antiVirus = new Network('clamav', 3310);

            //var_dump($antiVirus->ping());
            //var_dump($antiVirus->version());
            //var_dump($antiVirus->fileScan('/storage/uploads/app-1/5/9/f/e/59fecaed49645.pdf'));

            //$response->json($antiVirus->continueScan($device->getRoot()));
        }
    );
