<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\ClamAV\Network;
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
use Appwrite\Utopia\Response;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;
use Utopia\Validator\Nullable;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/storage/buckets/:bucketId/files')
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
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->inject('authorization')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        mixed $file,
        ?array $permissions,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
        Authorization $authorization,
        callable $locks
    ) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

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
            if (!empty($user->getId()) && !$isPrivilegedUser) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                }
            }
        }

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!$isAPIKey && !$isPrivilegedUser) {
            foreach (\Utopia\Database\Database::PERMISSIONS as $type) {
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

            $chunks = (int) ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
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

        $lockKey = 'storage:file:' . $project->getId() . ':' . $bucket->getId() . ':' . $fileId;

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        $completed = false;

        $mergeUploadMetadata = function (array $stored, array $current): array {
            $merged = \array_merge($stored, $current);

            if (isset($stored['parts']) || isset($current['parts'])) {
                $parts = $stored['parts'] ?? [];
                foreach (($current['parts'] ?? []) as $part => $value) {
                    $parts[(int) $part] = $value;
                }
                \ksort($parts);

                $merged['parts'] = $parts;
                $merged['chunks'] = \count($parts);
            }

            return $merged;
        };

        try {
            $locks($lockKey, 600, function () use ($bucket, &$chunks, $contentRange, $dbForProject, $deviceForFiles, $fileId, $fileName, $fileSize, &$metadata, $path, $permissions, $response, &$completed): void {
                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
                if (!$file->isEmpty()) {
                    $chunks = $file->getAttribute('chunksTotal', 1);
                    $uploaded = $file->getAttribute('chunksUploaded', 0);
                    $metadata = $file->getAttribute('metadata', []);

                    if ($uploaded === $chunks) {
                        if (empty($contentRange)) {
                            throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                        }

                        $response
                            ->setStatusCode(Response::STATUS_CODE_OK)
                            ->dynamic($file, Response::MODEL_FILE);

                        $completed = true;
                        return;
                    }
                }

                if ($file->isEmpty()) {
                    $deviceForFiles->prepareUpload($path, $metadata['content_type'] ?? '', $chunks, $metadata);

                    if (!empty($contentRange)) {
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
                            'chunksUploaded' => 0,
                            'search' => implode(' ', [$fileId, $fileName]),
                            'metadata' => $metadata,
                        ]);

                        try {
                            $dbForProject->createDocument('bucket_' . $bucket->getSequence(), $doc);
                        } catch (DuplicateException) {
                            throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                        } catch (NotFoundException) {
                            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                        }
                    }
                }
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'File upload is busy. Try again.');
        }

        if ($completed) {
            return;
        }

        $finalizeUpload = function (int $chunksUploaded) use ($authorization, $bucket, &$chunks, $contentRange, $dbForProject, $deviceForFiles, $fileId, $fileName, $fileSize, &$metadata, $mergeUploadMetadata, $path, $permissions, $queueForEvents, $response): void {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
            $uploaded = 0;

            if (!$file->isEmpty()) {
                $chunks = $file->getAttribute('chunksTotal', 1);
                $uploaded = $file->getAttribute('chunksUploaded', 0);
                $metadata = $mergeUploadMetadata($file->getAttribute('metadata', []), $metadata);

                if ($uploaded === $chunks) {
                    if (empty($contentRange)) {
                        throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                    }

                    $response
                        ->setStatusCode(Response::STATUS_CODE_OK)
                        ->dynamic($file, Response::MODEL_FILE);

                    return;
                }
            }

            $chunksUploaded = max($uploaded, $chunksUploaded, (int) ($metadata['chunks'] ?? 0));

            if ($chunksUploaded === $chunks && $uploaded < $chunks) {
                $deviceForFiles->finalizeUpload($path, $chunks, $metadata);

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
                $iv = '';
                $tag = null;
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
                    /**
                     * Skip authorization in updateDocument.
                     * Without this, the file creation will fail when user doesn't have update permission.
                     * However as with chunk upload even if we are updating, we are essentially creating a file
                     * adding it's new chunk so we rely on the create-permission check performed earlier.
                     */
                    $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, new Document([
                        '$permissions' => $permissions,
                        'signature' => $fileHash,
                        'mimeType' => $mimeType,
                        'sizeActual' => $sizeActual,
                        'algorithm' => $algorithm,
                        'openSSLVersion' => $openSSLVersion,
                        'openSSLCipher' => $openSSLCipher,
                        'openSSLTag' => $openSSLTag,
                        'openSSLIV' => $openSSLIV,
                        'metadata' => $metadata,
                        'chunksUploaded' => $chunksUploaded,
                    ])));
                }

                // Trigger after create success hook
                $this->afterCreateSuccess($file);
            } else {
                /**
                 * Skip authorization in updateDocument.
                 * Without this, the file creation will fail when user doesn't have update permission.
                 * However as with chunk upload even if we are updating, we are essentially creating a file
                 * adding it's new chunk so we rely on the create-permission check performed earlier.
                 */
                try {
                    $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, new Document([
                        'chunksUploaded' => $chunksUploaded,
                        'metadata' => $metadata,
                    ])));
                } catch (NotFoundException) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }
            }

            if ($chunksUploaded === $chunks) {
                $queueForEvents
                    ->setParam('bucketId', $bucket->getId())
                    ->setParam('fileId', $file->getId())
                    ->setContext('bucket', $bucket);
            }

            $metadata = null; // was causing leaks as it was passed by reference

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->dynamic($file, Response::MODEL_FILE);
        };

        try {
            $chunksUploaded = $deviceForFiles->uploadChunk($fileTmpName, $path, $chunk, $chunks, $metadata);

            if (empty($chunksUploaded)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed uploading file');
            }

            $locks($lockKey, 600, fn () => $finalizeUpload($chunksUploaded), timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'File upload is busy. Try again.');
        }
    }

    /**
     * Hook to run after file is created successfully
     *
     * @param Document $file
     * @return void
     */
    protected function afterCreateSuccess(Document $file)
    {
    }
}
