<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\Abuse\Limit;
use Appwrite\ClamAV\Network;
use Appwrite\ClamAV\ScanResult;
use Appwrite\Event\Event;
use Appwrite\Exiftool\Client as ExiftoolClient;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Storage\Encryption;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Filename;
use Appwrite\Utopia\Database\Validator\Folder;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Stream;
use Utopia\Storage\Cipher;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;
use Utopia\Validator\Nullable;

class Create extends Action
{
    use HTTP;

    /**
     * Lease for the per-file upload lock, in seconds. Refreshed before the
     * transfer and before completion so a single chunk always gets the full window.
     */
    private const LOCK_TTL = 600;

    /**
     * Metadata key recording which user started a chunked upload.
     * Not part of the public file model.
     */
    private const UPLOADER_ID = 'uploaderId';

    private const METADATA_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/avif',
        'image/gif',
        'image/tiff',
    ];

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
            ->label('usage.resource', 'bucket/{request.bucketId}/file/{response.$id}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId', 'chunkId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
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
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [PermissionType::Read, PermissionType::Update, PermissionType::Delete, PermissionType::Write])), 'An array of permission strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('folder', '', new Folder(), 'Virtual folder to place the file in, for example "photos/2026". Nest folders with `/`. Defaults to the bucket root.', true)
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
            ->inject('exiftool')
            ->callback($this->action(...));
    }

    /**
     * @return Document|null The file, when this request completed its upload
     */
    public function action(
        string $bucketId,
        string $fileId,
        mixed $file,
        ?array $permissions,
        string $folder,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
        Authorization $authorization,
        callable $locks,
        ExiftoolClient $exiftool
    ): ?Document {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$authorization->isValid(new Input(PermissionType::Create, $bucket->getPermissionsByType(PermissionType::Create)))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $allowedPermissions = [
            PermissionType::Read,
            PermissionType::Update,
            PermissionType::Delete,
        ];

        // Map aggregate permissions to into the set of individual permissions they represent.
        $permissions = Permission::aggregate($permissions, $allowedPermissions);

        // Add permissions for current the user if none were provided.
        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($user->getId()) && !$isPrivilegedUser) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = (new Permission($permission->value, 'user', $user->getId()))->toString();
                }
            }
        }

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!$isAPIKey && !$isPrivilegedUser) {
            foreach ([PermissionType::Read, PermissionType::Create, PermissionType::Update, PermissionType::Delete] as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type->value) {
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

        $contentRange = $request->getHeaderLine('content-range');
        $fileId = $fileId === 'unique()' ? Id::unique() : $fileId;
        $folder = Folder::normalize($folder);
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $fileId = $request->getHeaderLine('x-appwrite-id', $fileId);
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

            // A range starting at the declared size has no bytes left to address.
            if ($start >= $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }
        }

        $filename = new Filename(0, 0);
        if (!$filename->isValid($fileName)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $filename->getDescription());
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

        $contentType = $deviceForLocal->getFileMimeType($fileTmpName);
        $metadata = ['content_type' => $contentType];
        // A resumed upload keeps the key stored with its first chunk.
        if ($bucket->getAttribute('encryption', true)) {
            $metadata['encryption'] = Encryption::create();
        }
        $completed = false;
        $finished = null;
        // True when prepareUpload created the pending document in this request.
        // Finalize still sees that document, but it is not a resume.
        $createdInRequest = false;

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

            if (isset($stored['digests']) || isset($current['digests'])) {
                $digests = ($current['digests'] ?? []) + ($stored['digests'] ?? []);
                \ksort($digests);

                $merged['digests'] = $digests;
            }

            return $merged;
        };

        $prepareUpload = function () use ($authorization, $bucket, &$chunks, $contentRange, &$createdInRequest, $dbForProject, $deviceForFiles, $fileId, $fileName, $fileSize, &$metadata, $folder, $path, $permissions, $response, &$completed, $user): void {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
            if (!$file->isEmpty()) {
                $chunks = $file->getAttribute('chunksTotal', 1);
                $uploaded = $file->getAttribute('chunksUploaded', 0);
                $metadata = $file->getAttribute('metadata', []);

                if ($uploaded === $chunks && empty($contentRange)) {
                    throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                }

                $this->assertResumeAllowed($bucket, $file, $authorization, $user);

                // The chunk count and size were fixed by the first chunk.
                if ((int) $file->getAttribute('sizeOriginal', 0) !== $fileSize) {
                    throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
                }

                if ($uploaded === $chunks) {
                    $response
                        ->setStatusCode(Response::STATUS_CODE_OK)
                        ->dynamic($file, Response::MODEL_FILE);

                    $completed = true;

                    return;
                }
            }

            if ($file->isEmpty()) {
                $deviceForFiles->prepare($path, $metadata['content_type'] ?? '', $chunks, $metadata);

                if (!empty($contentRange)) {
                    $doc = new Document([
                        '$id' => Id::custom($fileId),
                        '$permissions' => $permissions,
                        'bucketId' => $bucket->getId(),
                        'bucketInternalId' => $bucket->getSequence(),
                        'name' => $fileName,
                        'folder' => $folder,
                        'path' => $path,
                        'signature' => '',
                        'mimeType' => '',
                        'sizeOriginal' => $fileSize,
                        'sizeActual' => 0,
                        'algorithm' => Compression::NONE,
                        'comment' => '',
                        'chunksTotal' => $chunks,
                        'chunksUploaded' => 0,
                        'search' => implode(' ', [$fileId, $fileName]),
                        'metadata' => \array_merge($metadata, [self::UPLOADER_ID => $user->getId()]),
                    ]);

                    try {
                        $dbForProject->createDocument('bucket_' . $bucket->getSequence(), $doc);
                        $createdInRequest = true;
                    } catch (DuplicateException) {
                        throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                    } catch (NotFoundException) {
                        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                    }
                }
            }
        };

        $finalizeUpload = function (int $chunksUploaded) use ($authorization, $bucket, &$chunks, $contentRange, &$createdInRequest, $dbForProject, $deviceForFiles, $exiftool, $fileId, $fileName, $fileSize, &$metadata, $mergeUploadMetadata, $folder, $path, $permissions, $queueForEvents, $response, $user, &$finished): void {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
            $uploaded = 0;

            if (!$file->isEmpty()) {
                $chunks = $file->getAttribute('chunksTotal', 1);
                $uploaded = $file->getAttribute('chunksUploaded', 0);
                $metadata = $mergeUploadMetadata($file->getAttribute('metadata', []), $metadata);

                if ($uploaded === $chunks && empty($contentRange)) {
                    throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                }

                // The document was created earlier in this request. Checking it
                // again would reject a guest, whose user id is empty, after the
                // chunk has already been transferred.
                if (!$createdInRequest) {
                    $this->assertResumeAllowed($bucket, $file, $authorization, $user);
                }

                if ($uploaded === $chunks) {
                    $queueForEvents->reset();

                    $response
                        ->setStatusCode(Response::STATUS_CODE_OK)
                        ->dynamic($file, Response::MODEL_FILE);

                    return;
                }
            }

            if (empty($chunksUploaded)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed uploading file');
            }

            // Count distinct completed parts, including previously persisted chunks.
            $chunksUploaded = max($uploaded, isset($metadata['parts']) ? \count($metadata['parts']) : $chunksUploaded);

            if ($chunksUploaded === $chunks && $uploaded < $chunks) {
                $deviceForFiles->finalize($path, $chunks, $metadata);
                // Reuse one metadata snapshot instead of separate backend HEADs.
                $info = $deviceForFiles->getFileInfo($path);

                // Files uploaded before segmented encryption have no key and were stored as sent.
                $cipher = isset($metadata['encryption']) ? Encryption::cipher($metadata['encryption']) : null;

                // A chunk can carry more or fewer bytes than its range announces.
                if (!empty($contentRange) && $info->size !== ($cipher === null ? $fileSize : Cipher::size($fileSize))) {
                    $removed = $deviceForFiles->delete($path);

                    if ($removed && !$file->isEmpty() && !$authorization->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId))) {
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
                    }

                    throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
                }

                if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled' && $bucket->getAttribute('antivirus', true) && $fileSize <= APP_LIMIT_ANTIVIRUS && $deviceForFiles->getType() === DeviceType::Local) {
                    $antivirus = new Network(
                        System::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                        (int) System::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
                    );

                    // ClamAV reads a path, so an encrypted file is scanned from a decrypted temporary copy.
                    $temporary = $cipher === null ? null : \tempnam(\sys_get_temp_dir(), 'scan');

                    try {
                        // A missing or short copy would scan clean, so it fails the scan instead.
                        if ($cipher !== null) {
                            $plaintext = $cipher->decrypt((string) $deviceForFiles->read($path), 0, true);
                            if (!\is_string($temporary) || \file_put_contents($temporary, $plaintext) !== \strlen($plaintext)) {
                                throw new \RuntimeException('Unable to write the decrypted copy for scanning');
                            }
                        }

                        $scan = $antivirus->scanInStream(\is_string($temporary) ? $temporary : $path);
                    } catch (\RuntimeException $e) {
                        $scan = ScanResult::failed($e->getMessage());
                    } finally {
                        if (\is_string($temporary)) {
                            \unlink($temporary);
                        }
                    }

                    if (!$scan->isClean()) {
                        // A pending record would be left pointing at chunks that are already joined. Keep it
                        // while the joined file is still on disk, so the removal can be retried by deleting the file.
                        $removed = $deviceForFiles->delete($path);

                        if ($removed && !$file->isEmpty() && !$authorization->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId))) {
                            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
                        }
                    }

                    if ($scan->isInfected()) {
                        throw new Exception(Exception::STORAGE_INVALID_FILE);
                    }

                    if ($scan->hasFailed()) {
                        throw new Exception(
                            Exception::GENERAL_SERVER_ERROR,
                            'Unable to scan the uploaded file: ' . $scan->getReply()
                        );
                    }
                }

                // The stored bytes of an encrypted file say nothing about its type, so use the first chunk's.
                $mimeType = $cipher === null ? ($info->mimeType ?? $deviceForFiles->getFileMimeType($path)) : ($metadata['content_type'] ?? '');
                $formats = Config::getParam('storage-formats');

                $allowed = $bucket->getAttribute('allowedFileExtensions', []);
                $signed = isset($formats['signatures'][\strtolower(\pathinfo($fileName, PATHINFO_EXTENSION))]);
                $types = \array_merge(...\array_map(fn (string $extension): array => $formats['signatures'][\strtolower($extension)] ?? [], $allowed));
                if (!empty($allowed) && $signed && !\in_array($mimeType, $formats['ambiguous'], true) && !\in_array($mimeType, $types, true)) {
                    $removed = $deviceForFiles->delete($path);

                    if ($removed && !$file->isEmpty() && !$authorization->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId))) {
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
                    }

                    throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File content type not allowed');
                }

                if (\in_array($mimeType, $formats['ambiguous'], true)) {
                    $mimeType = $formats['extensions'][\strtolower(\pathinfo($fileName, PATHINFO_EXTENSION))] ?? $mimeType;
                }

                // The signature describes the plaintext, in the format the device reports for it.
                $fileHash = match (true) {
                    $cipher === null => $info->etag ?? $deviceForFiles->getFileHash($path),
                    $chunks > 1 && $deviceForFiles->getType() === DeviceType::Local => $this->hash($deviceForFiles, $path, $fileSize, $metadata),
                    default => $this->signature($metadata['digests'] ?? []),
                };

                $algorithm = $bucket->getAttribute('compression', Compression::NONE);
                if ($fileSize > APP_STORAGE_READ_BUFFER) {
                    $algorithm = Compression::NONE;
                }
                $strip = $bucket->getAttribute('stripMetadata', false) && $fileSize <= APP_STORAGE_READ_BUFFER && \in_array($mimeType, self::METADATA_MIME_TYPES, true);
                // An upload started before segmented encryption is encrypted now, as it was before.
                $encrypt = $cipher === null && $bucket->getAttribute('encryption', true) && $fileSize <= APP_STORAGE_READ_BUFFER;

                // Changes to the content are made in memory and written once, still encrypted.
                $data = null;
                if ($strip || $algorithm !== Compression::NONE || $encrypt) {
                    $data = (string) $deviceForFiles->read($path);
                    if ($cipher !== null) {
                        $data = $cipher->decrypt($data, 0, true);
                    }

                    // Strip before hashing so the signature and sizes describe the file we store.
                    $stripped = $strip ? $this->stripMetadata($exiftool, $data, $path) : null;
                    if ($stripped !== null) {
                        $data = $stripped;
                        $fileSize = \strlen($data);
                        $fileHash = \md5($data);
                    }

                    $data = match ($algorithm) {
                        Compression::NONE => $data,
                        Compression::ZSTD => (new Zstd())->compress($data),
                        default => (new GZIP())->compress($data),
                    };

                    if ($encrypt) {
                        $metadata['encryption'] = Encryption::create();
                        $cipher = Encryption::cipher($metadata['encryption']);
                    }

                    if ($cipher !== null) {
                        $data = $cipher->encrypt($data, 0, true);
                    }

                    if (!$deviceForFiles->write($path, new Stream($data), $mimeType)) {
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to save file');
                    }
                }

                $sizeActual = $data !== null ? \strlen($data) : $info->size;

                $openSSLVersion = null;
                $openSSLCipher = null;
                $openSSLTag = null;
                $openSSLIV = null;

                if ($cipher !== null) {
                    $openSSLVersion = $metadata['encryption']['version'];
                    $openSSLCipher = Cipher::NAME;
                }

                if ($file->isEmpty()) {
                    $doc = new Document([
                        '$id' => $fileId,
                        '$permissions' => $permissions,
                        'bucketId' => $bucket->getId(),
                        'bucketInternalId' => $bucket->getSequence(),
                        'name' => $fileName,
                        'folder' => $folder,
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
                        'metadata' => \array_merge($metadata, [self::UPLOADER_ID => $user->getId()]),
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
                     * This request either created the file or already passed
                     * assertResumeAllowed(). The uploader, including a guest, may
                     * not hold update permission. Permissions from the first chunk
                     * stay as they are.
                     */
                    $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, new Document([
                        'signature' => $fileHash,
                        'mimeType' => $mimeType,
                        'sizeOriginal' => $fileSize,
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

                $finished = $file;
            } else {
                /**
                 * Skip authorization in updateDocument.
                 * This request either created the file or already passed
                 * assertResumeAllowed(). The uploader, including a guest, may
                 * not hold update permission.
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
            } else {
                $queueForEvents->reset();
            }

            $metadata = null; // was causing leaks as it was passed by reference

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->dynamic($file, Response::MODEL_FILE);
        };

        try {
            // upload() can finalize and remove chunk files itself. Keep preparation,
            // transfer and document completion under the same per-file lock.
            $locks($lockKey, self::LOCK_TTL, function (Distributed $lock) use ($prepareUpload, $finalizeUpload, &$completed, $queueForEvents, $deviceForFiles, $deviceForLocal, $fileTmpName, $contentType, $path, $chunk, &$chunks, &$metadata): void {
                $prepareUpload();

                if ($completed) {
                    $queueForEvents->reset();

                    return;
                }

                // Out-of-order uploads take the type from the first chunk, whenever it arrives.
                if ($chunk === 1) {
                    $metadata['content_type'] = $contentType;
                }

                // Restart the lease so the transfer gets the full window,
                // regardless of how long preparation took.
                if (!$lock->refresh()) {
                    throw new LockContention('Upload lease lost before transfer: ' . $lock->token());
                }

                $data = $deviceForLocal->read($fileTmpName);
                // Encrypt before the chunk reaches storage. Files resumed from before segmented encryption have no key.
                if (isset($metadata['encryption'])) {
                    $data = $this->encrypt(Encryption::cipher($metadata['encryption']), (string) $data, $chunk, $chunks, $metadata);
                }

                $chunksUploaded = $deviceForFiles->upload(
                    $data,
                    $path,
                    $metadata['content_type'] ?? '',
                    $chunk,
                    $chunks,
                    $metadata
                );

                // Never record completion under a lapsed lease: another request
                // may already own the file and be finalizing it.
                if (!$lock->isHeld()) {
                    throw new LockContention('Upload lease lost after transfer: ' . $lock->token());
                }

                $finalizeUpload($chunksUploaded);
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'File upload is busy. Try again.');
        }

        return $finished;
    }

    /**
     * Returns the stripped file, or null when the file is kept as uploaded.
     */
    private function stripMetadata(ExiftoolClient $exiftool, string $data, string $path): ?string
    {
        try {
            return $exiftool->strip($data);
        } catch (\Throwable $th) {
            Console::warning("Image metadata was not removed from {$path}: {$th->getMessage()}");
            return null;
        }
    }

    /**
     * Encrypt one chunk at its place in the file, and keep the digest of its plaintext for the signature.
     * Every chunk but the last must be full, so chunks line up with segments. A wrong-sized last chunk
     * fails the size check when the file is finalized.
     *
     * @param array<string, mixed> $metadata
     */
    private function encrypt(Cipher $cipher, string $data, int $chunk, int $chunks, array &$metadata): Stream
    {
        if ($chunk < $chunks && \strlen($data) !== APP_LIMIT_UPLOAD_CHUNK_SIZE) {
            throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
        }

        $metadata['digests'][$chunk] = \md5($data);

        return new Stream($cipher->encrypt($data, ($chunk - 1) * \intdiv(APP_LIMIT_UPLOAD_CHUNK_SIZE, Cipher::SEGMENT), $chunk === $chunks));
    }

    /**
     * MD5 of the whole plaintext, as the local device reports for files it stores as sent. Streams one segment at a time.
     *
     * @param array<string, mixed> $metadata
     */
    private function hash(Device $deviceForFiles, string $path, int $fileSize, array $metadata): string
    {
        $context = \hash_init('md5');
        $file = new Document([
            'path' => $path,
            'sizeOriginal' => $fileSize,
            'openSSLCipher' => Cipher::NAME,
            'metadata' => $metadata,
        ]);
        (new Encryption($file))->stream($deviceForFiles, static fn (string $chunk) => \hash_update($context, $chunk));

        return \hash_final($context);
    }

    /**
     * MD5 of a single chunk, or the S3 multipart ETag form (MD5 of the part digests, dash, part count).
     *
     * @param array<int, string> $digests
     */
    private function signature(array $digests): string
    {
        \ksort($digests);

        if (\count($digests) === 1) {
            return \reset($digests);
        }

        return \md5(\implode('', \array_map(\hex2bin(...), $digests))) . '-' . \count($digests);
    }

    /**
     * Chunked resume loads the file with authorization skipped, because the
     * user who started the upload may not hold update permission yet.
     * Continue only when the caller may update the file (bucket update, or
     * file update when file security is on) or is the user who started it.
     */
    private function assertResumeAllowed(Document $bucket, Document $file, Authorization $authorization, User $user): void
    {
        if ($authorization->isValid(new Input(PermissionType::Update, $bucket->getPermissionsByType(PermissionType::Update)))) {
            return;
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        if ($fileSecurity && $authorization->isValid(new Input(PermissionType::Update, $file->getPermissionsByType(PermissionType::Update)))) {
            return;
        }

        $uploaderId = $file->getAttribute('metadata', [])[self::UPLOADER_ID] ?? '';
        // Guests have no user id. An empty value is not ownership, or every
        // guest could resume every other guest's upload.
        if ($uploaderId !== '' && $uploaderId === $user->getId()) {
            return;
        }

        throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
    }
}
