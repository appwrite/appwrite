<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files\View;

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getFileView';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/storage/buckets/:bucketId/files/:fileId/view')
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
                contentType: ContentType::ANY,
                type: MethodType::LOCATION
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
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        ?string $token,
        Response $response,
        Request $request,
        Database $dbForProject,
        string $mode,
        Document $resourceToken,
        Device $deviceForFiles
    ) {
        /* @type Document $bucket */
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp(Authorization::getRoles());
        $isPrivilegedUser = User::isPrivileged(Authorization::getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$fileSecurity && !$valid && !$isToken) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($fileSecurity && !$valid && !$isToken) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            /* @type Document $file */
            $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
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
    }
}
