<?php

namespace Appwrite\Platform\Modules\S3\Http\S3;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\S3\Auth\SignatureV4;
use Appwrite\Platform\Modules\S3\Requests\AwsChunked;
use Appwrite\Platform\Modules\S3\Responses\S3Xml;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Folder;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Query;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;

abstract class Base extends Action
{
    use HTTP;

    protected const PREFIX = '/v1/s3';
    protected const MULTIPART_TTL = 86400;

    public function __construct(string $method)
    {
        $this
            ->setHttpMethod($method)
            ->setHttpPath(self::PREFIX)
            ->httpAlias(self::PREFIX . '/*')
            ->desc('S3 compatibility API')
            ->groups(['s3'])
            ->label('scope', 'public')
            ->label('origin', '*')
            ->label('event', 'buckets.[bucketId].files.[fileId]')
            ->label('audits.resource', 'file/{response.$id}')
            ->label('usage.resource', 'file/{response.$id}')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('team')
            ->inject('user')
            ->inject('deviceForFiles')
            ->inject('locks')
            ->inject('cache')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('publisherForAudits')
            ->inject('queueForRealtime')
            ->inject('publisherForFunctions')
            ->inject('queueForWebhooks')
            ->inject('eventProcessor')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        Document $team,
        User $user,
        Device $deviceForFiles,
        callable $locks,
        Cache $cache,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        AuditPublisher $publisherForAudits,
        Event $queueForRealtime,
        FunctionPublisher $publisherForFunctions,
        Event $queueForWebhooks,
        EventProcessor $eventProcessor,
    ): void {
        try {
            $this->route($request, $response, $dbForProject, $project, $team, $user, $deviceForFiles, $locks, $cache, $queueForEvents, $publisherForDeletes, $publisherForAudits, $queueForRealtime, $publisherForFunctions, $queueForWebhooks, $eventProcessor);
        } catch (AppwriteException $error) {
            $this->sendError($response, $this->mapError($error), $error->getMessage(), $request->getURI(), $this->mapStatus($error));
        } catch (\Throwable $error) {
            $this->sendError($response, 'InternalError', $error->getMessage(), $request->getURI(), Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
        }
    }

    abstract protected function route(Request $request, Response $response, Database $dbForProject, Document $project, Document $team, User $user, Device $deviceForFiles, callable $locks, Cache $cache, Event $queueForEvents, DeletePublisher $publisherForDeletes, AuditPublisher $publisherForAudits, Event $queueForRealtime, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, EventProcessor $eventProcessor): void;

    protected function authorize(Request $request, Document $project, Document $team, User $user, array $scopes): void
    {
        (new SignatureV4())->verify($request, $project, $team, $user, $scopes);
    }

    protected function parts(Request $request): array
    {
        $path = \parse_url($request->getURI(), PHP_URL_PATH) ?: '';
        $path = \rawurldecode($path);
        if ($path === self::PREFIX) {
            return ['', ''];
        }

        $tail = \ltrim(\substr($path, \strlen(self::PREFIX)), '/');
        [$bucketId, $key] = \array_pad(\explode('/', $tail, 2), 2, '');
        return [$bucketId, $key];
    }

    protected function query(Request $request, string $key, string $default = ''): string
    {
        $query = $request->getServer('query_string', '') ?: (\parse_url($request->getURI(), PHP_URL_QUERY) ?: '');
        \parse_str($query, $params);
        return (string) ($params[$key] ?? $default);
    }

    protected function hasQuery(Request $request, string $key): bool
    {
        $query = $request->getServer('query_string', '') ?: (\parse_url($request->getURI(), PHP_URL_QUERY) ?: '');
        \parse_str($query, $params);
        return \array_key_exists($key, $params);
    }

    protected function bucket(Database $dbForProject, string $bucketId): Document
    {
        $bucket = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));
        if ($bucket->isEmpty() || !$bucket->getAttribute('enabled', true)) {
            throw new AppwriteException(AppwriteException::STORAGE_BUCKET_NOT_FOUND);
        }
        if ($bucket->getAttribute('encryption', true) || $bucket->getAttribute('compression', 'none') !== 'none') {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'S3 access requires a bucket with encryption and compression disabled.');
        }
        return $bucket;
    }

    protected function objectKey(Document $file): string
    {
        return $file->getAttribute('folder', '') . $file->getAttribute('name', $file->getId());
    }

    /**
     * @return array{folder: string, name: string}
     */
    protected function objectPath(string $key): array
    {
        $slash = \strrpos($key, '/');
        $folder = $slash === false ? '' : \substr($key, 0, $slash + 1);
        $name = $slash === false ? $key : \substr($key, $slash + 1);
        $validator = new Folder();

        if ($name === '' || !$validator->isValid($folder)) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, $validator->getDescription());
        }

        return [
            'folder' => Folder::normalize($folder),
            'name' => $name,
        ];
    }

    protected function isFolderMarker(string $key): bool
    {
        return $key !== '' && \str_ends_with($key, '/');
    }

    protected function multipartStateKey(Document $project, string $uploadId): string
    {
        return 's3.multipart.state.' . $project->getId() . '.' . $uploadId;
    }

    protected function multipartIndexKey(Document $project, Document $bucket): string
    {
        return 's3.multipart.index.' . $project->getId() . '.' . $bucket->getId();
    }

    protected function createMultipartUpload(Database $dbForProject, Cache $cache, Document $project, Device $deviceForFiles, Document $bucket, string $key, string $uploadId, string $contentType, array $metadata): array
    {
        $existing = $this->findObject($dbForProject, $bucket, $key);
        $object = $existing === null ? $this->objectPath($key) : [
            'folder' => $existing->getAttribute('folder', ''),
            'name' => $existing->getAttribute('name', ''),
        ];
        // Stage each upload independently; destination identity is chosen only at locked completion.
        $fileId = ID::unique();
        $name = $object['name'];
        $this->validateFileConstraints($bucket, $name, 0);
        $path = $this->path($deviceForFiles, $bucket, $fileId, $name);
        $contentType = $this->contentType($name, $contentType);
        $deviceMetadata = [];
        $deviceForFiles->prepare($path, $contentType, 2, $deviceMetadata);

        $upload = [
            'uploadId' => $uploadId,
            'bucketId' => $bucket->getId(),
            'key' => $key,
            'requestedKey' => $key,
            'folder' => $object['folder'],
            'name' => $name,
            'fileId' => $fileId,
            'path' => $path,
            'contentType' => $contentType,
            'metadata' => ['object' => $metadata, 'device' => $deviceMetadata],
            'parts' => [],
            'initiated' => \gmdate('c'),
        ];

        $cache->save($this->multipartStateKey($project, $uploadId), \json_encode($upload, JSON_THROW_ON_ERROR));
        $cache->save($this->multipartIndexKey($project, $bucket), $uploadId, $uploadId);

        return $upload;
    }

    protected function multipartUpload(Cache $cache, Document $project, string $uploadId): array
    {
        $upload = $cache->load($this->multipartStateKey($project, $uploadId), self::MULTIPART_TTL);
        if (!\is_string($upload)) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Multipart upload not found.');
        }

        return \json_decode($upload, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function multipartUploadForBucket(Cache $cache, Document $project, string $uploadId, string $bucketId, string $key = ''): array
    {
        $upload = $this->multipartUpload($cache, $project, $uploadId);
        if (($upload['bucketId'] ?? '') !== $bucketId || ($key !== '' && isset($upload['requestedKey']) && $upload['requestedKey'] !== $key)) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Multipart upload not found.');
        }

        return $upload;
    }

    protected function saveMultipartUpload(Cache $cache, Document $project, array $upload): void
    {
        $cache->save($this->multipartStateKey($project, (string) $upload['uploadId']), \json_encode($upload, JSON_THROW_ON_ERROR));
    }

    protected function uploadMultipartPart(Cache $cache, Document $project, Device $deviceForFiles, array $upload, int $partNumber, string $body, string $etag): array
    {
        $metadata = $upload['metadata'] ?? [];
        $deviceMetadata = $metadata['device'] ?? [];
        $chunks = \max(2, (int) ($deviceMetadata['chunks'] ?? 0) + 2, $partNumber + 1);
        $deviceForFiles->upload(
            new Stream($body),
            (string) $upload['path'],
            (string) ($upload['contentType'] ?? 'application/octet-stream'),
            $partNumber,
            $chunks,
            $deviceMetadata
        );

        $metadata['device'] = $deviceMetadata;
        $upload['metadata'] = $metadata;
        $upload['parts'][(string) $partNumber] = ['etag' => $etag, 'size' => \strlen($body)];
        $this->saveMultipartUpload($cache, $project, $upload);

        return $upload;
    }

    protected function unsupportedSubresource(Request $request): bool
    {
        // Requests are SigV4-signed, so every query key is deliberate SDK output.
        // Any key outside this allow-list is an S3 feature Appwrite Storage does
        // not implement (?tagging, ?legal-hold, ?restore, ?publicAccessBlock, …);
        // refusing loudly beats falling through to an object write or a bucket
        // delete the client never asked for.
        $allowed = [
            'X-Amz-Algorithm', 'X-Amz-Content-Sha256', 'X-Amz-Credential',
            'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-Signature',
            'X-Amz-SignedHeaders',
            'acl', 'continuation-token', 'delete', 'delimiter', 'encoding-type',
            'fetch-owner', 'list-type', 'location', 'marker', 'max-keys',
            'partNumber', 'prefix', 'response-cache-control',
            'response-content-disposition', 'response-content-encoding',
            'response-content-language', 'response-content-type',
            'response-expires', 'start-after', 'uploadId', 'uploads',
            'versionId', 'x-id',
        ];

        $query = $request->getServer('query_string', '') ?: (\parse_url($request->getURI(), PHP_URL_QUERY) ?: '');
        \parse_str($query, $params);
        foreach (\array_keys($params) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    protected function requestBody(Request $request): string
    {
        $payload = $request->getRawPayload();
        $contentSha256 = \strtoupper(\trim($request->getHeaderLine('x-amz-content-sha256')));
        if (\str_starts_with($contentSha256, 'STREAMING-AWS4-HMAC-SHA256-')) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Signed aws-chunked payloads are not supported.');
        }

        if (!AwsChunked::applies($contentSha256, $request->getHeaderLine('content-encoding'))) {
            return $payload;
        }

        $declaredLength = $request->getHeaderLine('x-amz-decoded-content-length');
        return AwsChunked::decode($payload, $declaredLength === '' ? null : (int) $declaredLength);
    }

    protected function path(Device $deviceForFiles, Document $bucket, string $fileId, string $key): string
    {
        $extension = \pathinfo($key, PATHINFO_EXTENSION) ?: 'bin';
        $path = $deviceForFiles->getPath($fileId . '.' . $extension);
        return \str_ireplace($deviceForFiles->getRoot(), $deviceForFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path);
    }

    protected function contentType(string $name, string $contentType, string $body = ''): string
    {
        $contentType = \strtolower(\trim(\explode(';', $contentType)[0]));
        if ($contentType !== '' && $contentType !== 'application/octet-stream' && $contentType !== 'binary/octet-stream') {
            return $contentType;
        }

        $extension = \strtolower(\pathinfo($name, PATHINFO_EXTENSION));
        $byExtension = [
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'css' => 'text/css',
            'csv' => 'text/csv',
            'gif' => 'image/gif',
            'htm' => 'text/html',
            'html' => 'text/html',
            'ico' => 'image/x-icon',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'js' => 'text/javascript',
            'json' => 'application/json',
            'm4v' => 'video/x-m4v',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain',
            'wasm' => 'application/wasm',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
        ];
        if (isset($byExtension[$extension])) {
            return $byExtension[$extension];
        }

        if ($body !== '') {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (\is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }

    protected function validateFileConstraints(Document $bucket, string $name, int $size): void
    {
        $maximumFileSize = (int) $bucket->getAttribute('maximumFileSize', 0);
        if ($maximumFileSize > 0 && $size > $maximumFileSize) {
            throw new AppwriteException(AppwriteException::STORAGE_INVALID_FILE_SIZE, 'File size not allowed');
        }

        $allowed = $bucket->getAttribute('allowedFileExtensions', []);
        if (!empty($allowed)) {
            $extension = \strtolower(\pathinfo($name, PATHINFO_EXTENSION));
            $allowed = \array_map(fn (string $value): string => \strtolower(\ltrim($value, '.')), $allowed);
            if ($extension === '' || !\in_array($extension, $allowed, true)) {
                throw new AppwriteException(AppwriteException::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
            }
        }
    }

    protected function validateCopySize(Document $bucket, Document $sourceFile): void
    {
        $maximumFileSize = (int) $bucket->getAttribute('maximumFileSize', 0);
        $sourceSize = (int) $sourceFile->getAttribute('sizeOriginal', 0);

        if ($maximumFileSize > 0 && $sourceSize > $maximumFileSize) {
            throw new AppwriteException(AppwriteException::STORAGE_INVALID_FILE_SIZE, 'Copied file size not allowed');
        }
    }

    protected function queueFileEvent(Event $queueForEvents, Response $response, Document $bucket, Document $file, string $action): void
    {
        $queueForEvents
            ->setEvent('buckets.[bucketId].files.[fileId].' . $action)
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($file, Response::MODEL_FILE));
    }

    /**
     * Batch operations emit one event per file, but the events queue holds a
     * single event, so the shutdown flush would only carry the last file — and
     * only when the whole batch completes. Dispatch realtime, functions and
     * webhooks inline as each file is deleted — the platform's bulk pattern
     * (Documents\Action::triggerBulk) — so a later batch failure cannot lose
     * events for files already gone, then reset the queues so the shutdown
     * flush does not re-fire this file.
     */
    protected function triggerFileEvent(Event $queueForEvents, Event $queueForRealtime, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, EventProcessor $eventProcessor, Database $dbForProject, Response $response, Document $bucket, Document $file, string $action): void
    {
        $queueForEvents
            ->setEvent('buckets.[bucketId].files.[fileId].' . $action)
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($file, Response::MODEL_FILE));

        // Get project and function/webhook events (cached per project)
        $project = $queueForEvents->getProject();
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        $queueForRealtime
            ->from($queueForEvents)
            ->trigger();

        $generatedEvents = Event::generateEvents(
            $queueForEvents->getEvent(),
            $queueForEvents->getParams()
        );

        if (!empty($functionsEvents)) {
            foreach ($generatedEvents as $event) {
                if (isset($functionsEvents[$event])) {
                    $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                        event: $queueForEvents->getEvent(),
                        params: $queueForEvents->getParams(),
                        project: $queueForEvents->getProject(),
                        user: $queueForEvents->getUser(),
                        userId: $queueForEvents->getUserId(),
                        payload: $queueForEvents->getPayload(),
                        platform: $queueForEvents->getPlatform(),
                    ));
                    break;
                }
            }
        }

        if (!empty($webhooksEvents)) {
            foreach ($generatedEvents as $event) {
                if (isset($webhooksEvents[$event])) {
                    $queueForWebhooks
                        ->from($queueForEvents)
                        ->trigger();
                    break;
                }
            }
        }

        $queueForEvents->reset();
        $queueForRealtime->reset();
        $queueForWebhooks->reset();
    }

    protected function queueBucketEvent(Event $queueForEvents, Response $response, Document $bucket, string $action): void
    {
        $queueForEvents
            ->setEvent('buckets.[bucketId].' . $action)
            ->setParam('bucketId', $bucket->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($bucket, Response::MODEL_BUCKET));
    }

    protected function enqueueFileCacheDelete(DeletePublisher $publisherForDeletes, Event $queueForEvents, Document $bucket, Document $file): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_CACHE_BY_RESOURCE,
            resource: 'file/' . $file->getId(),
            resourceType: 'bucket/' . $bucket->getId(),
        ));
    }

    protected function enqueueAudit(AuditPublisher $publisherForAudits, Request $request, Document $project, User $user, string $event, Document $resource, string $resourceType): void
    {
        $publisherForAudits->enqueue(new AuditMessage(
            event: $event,
            payload: $resource->getArrayCopy(),
            project: $project,
            user: $user,
            resource: $resourceType . '/' . $resource->getId(),
            mode: 'api',
            ip: $request->getIP(),
            userAgent: $request->getUserAgent(''),
            hostname: $request->getHostname(),
        ));
    }

    protected function createBucket(Database $dbForProject, string $bucketId): void
    {
        $files = (Config::getParam('collections', [])['buckets'] ?? [])['files'] ?? [];
        if (empty($files)) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Files collection is not configured.');
        }

        $attributes = [];
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
                'format' => $attribute['format'] ?? '',
            ]);
        }

        $indexes = [];
        foreach ($files['indexes'] as $index) {
            $indexes[] = new Document([
                '$id' => $index['$id'],
                'type' => $index['type'],
                'attributes' => $index['attributes'],
                'lengths' => $index['lengths'] ?? [],
                'orders' => $index['orders'] ?? [],
            ]);
        }

        $permissions = Permission::aggregate(null) ?? [];

        try {
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->createDocument('buckets', new Document([
                '$id' => $bucketId,
                '$collection' => 'buckets',
                '$permissions' => $permissions,
                'name' => $bucketId,
                'maximumFileSize' => (int) \Utopia\System\System::getEnv('_APP_STORAGE_LIMIT', 0),
                'allowedFileExtensions' => [],
                'fileSecurity' => false,
                'enabled' => true,
                'compression' => 'none',
                'encryption' => false,
                'antivirus' => false,
                'transformations' => true,
                'search' => $bucketId,
            ])));
        } catch (DuplicateException) {
            throw new AppwriteException(AppwriteException::STORAGE_BUCKET_ALREADY_EXISTS);
        }

        try {
            $bucket = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes, permissions: $permissions, documentSecurity: false));
        } catch (\Throwable $error) {
            // Roll back the bucket document so a failed collection creation does
            // not leave an unusable bucket with no backing collection.
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocument('buckets', $bucketId));
            throw $error;
        }
    }

    protected function putObject(Database $dbForProject, Device $deviceForFiles, Document $bucket, string $key, string $body, string $contentType, array $metadata = []): Document
    {
        $existing = $this->findObject($dbForProject, $bucket, $key);
        $object = $existing === null ? $this->objectPath($key) : [
            'folder' => $existing->getAttribute('folder', ''),
            'name' => $existing->getAttribute('name', ''),
        ];
        $fileId = $existing?->getId() ?: ID::unique();
        $folder = $object['folder'];
        $name = $object['name'];
        $this->validateFileConstraints($bucket, $name, \strlen($body));
        $previousPath = $existing?->getAttribute('path', '') ?? '';
        $path = $existing === null
            ? $this->path($deviceForFiles, $bucket, $fileId, $name)
            : $this->path($deviceForFiles, $bucket, ID::unique(), $name);
        $contentType = $this->contentType($name, $contentType, $body);
        $etag = \md5($body);

        if (!$deviceForFiles->write($path, new Stream($body), $contentType)) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Failed to save object.');
        }

        $attributes = [
            'name' => $name,
            'folder' => $folder,
            'path' => $path,
            'signature' => $etag,
            'mimeType' => $contentType,
            'sizeOriginal' => \strlen($body),
            'sizeActual' => $deviceForFiles->getFileSize($path),
            'search' => \implode(' ', [$fileId, $folder, $name]),
            'metadata' => $metadata,
        ];

        try {
            if ($existing !== null) {
                $file = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->updateDocument(
                    'bucket_' . $bucket->getSequence(),
                    $fileId,
                    new Document($attributes)
                ));
            } else {
                $file = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->createDocument('bucket_' . $bucket->getSequence(), new Document([
                    '$id' => ID::custom($fileId),
                    '$permissions' => [],
                    'bucketId' => $bucket->getId(),
                    'bucketInternalId' => $bucket->getSequence(),
                    ...$attributes,
                    'algorithm' => 'none',
                    'comment' => '',
                    'chunksTotal' => 1,
                    'chunksUploaded' => 1,
                ])));
            }
        } catch (\Throwable $error) {
            $deviceForFiles->delete($path);
            throw $error;
        }

        if ($previousPath !== '' && $previousPath !== $path) {
            try {
                $deviceForFiles->delete($previousPath);
            } catch (\Throwable) {
                // The replacement is committed; stale-path cleanup is best effort.
            }
        }

        return $file;
    }
    /**
     * @return array{0: Document, 1: string}
     */
    protected function putObjectLocked(callable $locks, Database $dbForProject, Document $project, Device $deviceForFiles, Document $bucket, string $key, string $body, string $contentType, array $metadata = []): array
    {
        // Storage permits duplicate names, so lookup and write must share one key-scoped critical section.
        return $locks($this->objectLockKey($project, $bucket, $key), 600, function () use ($dbForProject, $deviceForFiles, $bucket, $key, $body, $contentType, $metadata): array {
            $existing = $this->findObject($dbForProject, $bucket, $key);
            $file = $this->putObject($dbForProject, $deviceForFiles, $bucket, $key, $body, $contentType, $metadata);

            return [$file, $existing === null ? 'create' : 'update'];
        }, timeout: 120.0);
    }

    protected function objectLockKey(Document $project, Document $bucket, string $key): string
    {
        return 's3:object:' . $project->getId() . ':' . $bucket->getId() . ':' . \hash('sha256', $key);
    }

    /**
     * Serializes part uploads and abort of one multipart upload: the cached
     * upload state and the device chunk bookkeeping are read-modify-write, so
     * concurrent parts would drop each other's entries. Never held together
     * with the object lock — the lock pool fails fast instead of queueing.
     */
    protected function multipartLockKey(Document $project, string $uploadId): string
    {
        return 's3:multipart:' . $project->getId() . ':' . $uploadId;
    }

    protected function createObjectDocument(Database $dbForProject, Device $deviceForFiles, Document $bucket, string $folder, string $name, string $fileId, string $path, string $etag, string $contentType, int $size, int $chunksTotal, array $metadata = []): Document
    {
        $contentType = $this->contentType($name, $contentType);
        $document = new Document([
            '$id' => ID::custom($fileId),
            '$permissions' => [],
            'bucketId' => $bucket->getId(),
            'bucketInternalId' => $bucket->getSequence(),
            'name' => $name,
            'folder' => $folder,
            'path' => $path,
            'signature' => $etag,
            'mimeType' => $contentType,
            'sizeOriginal' => $size,
            'sizeActual' => $deviceForFiles->getFileSize($path),
            'algorithm' => 'none',
            'comment' => '',
            'chunksTotal' => $chunksTotal,
            'chunksUploaded' => $chunksTotal,
            'search' => \implode(' ', [$fileId, $folder, $name]),
            'metadata' => $metadata,
        ]);

        try {
            return $dbForProject->getAuthorization()->skip(fn () => $dbForProject->createDocument('bucket_' . $bucket->getSequence(), $document));
        } catch (DuplicateException) {
            return $dbForProject->getAuthorization()->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, new Document([
                'name' => $name,
                'folder' => $folder,
                'path' => $path,
                'signature' => $etag,
                'mimeType' => $contentType,
                'sizeOriginal' => $size,
                'sizeActual' => $deviceForFiles->getFileSize($path),
                'chunksTotal' => $chunksTotal,
                'chunksUploaded' => $chunksTotal,
                'search' => \implode(' ', [$fileId, $folder, $name]),
                'metadata' => $metadata,
            ])));
        }
    }

    protected function requestObjectMetadata(Request $request): array
    {
        $metadata = ['userMetadata' => []];
        foreach ($request->getHeaders() as $name => $values) {
            if (\str_starts_with($name, 'x-amz-meta-')) {
                $metadata['userMetadata'][\substr($name, \strlen('x-amz-meta-'))] = \implode(',', $values);
            }
        }

        $sse = $request->getHeaderLine('x-amz-server-side-encryption');
        if ($sse !== '') {
            $metadata['serverSideEncryption'] = $sse;
        }

        foreach ([
            'cache-control' => 'cacheControl',
            'content-disposition' => 'contentDisposition',
            'content-language' => 'contentLanguage',
            'expires' => 'expires',
        ] as $header => $attribute) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                $metadata[$attribute] = $value;
            }
        }

        $contentEncodings = \array_filter(
            \array_map('trim', \explode(',', $request->getHeaderLine('content-encoding'))),
            fn (string $encoding): bool => $encoding !== '' && \strcasecmp($encoding, 'aws-chunked') !== 0
        );
        if ($contentEncodings !== []) {
            $metadata['contentEncoding'] = \implode(', ', $contentEncodings);
        }

        return $metadata;
    }

    /**
     * @return array<int, string>
     */
    protected function deleteObjectKeys(string $body): array
    {
        if (!\preg_match_all('/<Key>\s*([^<]+)\s*<\/Key>/', $body, $matches)) {
            return [];
        }

        return \array_map('html_entity_decode', $matches[1]);
    }

    protected function deleteQuiet(string $body): bool
    {
        return \preg_match('/<Quiet>\s*true\s*<\/Quiet>/i', $body) === 1;
    }

    protected function getObject(Database $dbForProject, Document $bucket, string $key): Document
    {
        $file = $this->findObject($dbForProject, $bucket, $key);
        if ($file === null) {
            throw new AppwriteException(AppwriteException::STORAGE_FILE_NOT_FOUND);
        }
        return $file;
    }

    protected function findObject(Database $dbForProject, Document $bucket, string $key): ?Document
    {
        $collection = 'bucket_' . $bucket->getSequence();
        $object = $this->objectPath($key);
        $files = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find($collection, [
            Query::equal('folder', [$object['folder']]),
            Query::equal('name', [$object['name']]),
            Query::limit(2),
        ]));

        if (\count($files) > 1) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, "Multiple files match S3 object key '{$key}'.");
        }

        return $files[0] ?? null;
    }

    /**
     * @param array<Document> $files
     */
    protected function assertUniqueObjectKeys(array $files): void
    {
        $keys = [];
        foreach ($files as $file) {
            $key = $this->objectKey($file);
            if (isset($keys[$key])) {
                throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, "Multiple files match S3 object key '{$key}'.");
            }
            $keys[$key] = true;
        }
    }

    /**
     * @return array<int, array{partNumber: int, etag: string, size: int}>
     */
    protected function getMultipartParts(array $upload): array
    {
        $parts = [];
        foreach (($upload['parts'] ?? []) as $partNumber => $part) {
            $partNumber = (int) $partNumber;
            $parts[$partNumber] = [
                'partNumber' => $partNumber,
                'etag' => (string) ($part['etag'] ?? ''),
                'size' => (int) ($part['size'] ?? 0),
            ];
        }

        \ksort($parts);
        return \array_values($parts);
    }

    /**
     * @return array<int, array{key: string, uploadId: string, initiated: string}>
     */
    protected function getMultipartUploads(Cache $cache, Document $project, Document $bucket): array
    {
        $uploads = [];
        foreach ($cache->list($this->multipartIndexKey($project, $bucket)) as $uploadId) {
            $state = $cache->load($this->multipartStateKey($project, $uploadId), self::MULTIPART_TTL);
            if (!\is_string($state)) {
                $cache->purge($this->multipartIndexKey($project, $bucket), $uploadId);
                continue;
            }
            $upload = \json_decode($state, true, flags: JSON_THROW_ON_ERROR);

            $uploads[] = [
                'key' => (string) ($upload['requestedKey'] ?? $upload['key'] ?? ''),
                'uploadId' => $uploadId,
                'initiated' => (string) ($upload['initiated'] ?? ''),
            ];
        }

        return $uploads;
    }

    /**
     * @return array<int, string>
     */
    protected function completedParts(string $body): array
    {
        if (!\preg_match_all('/<Part>\s*<PartNumber>\s*(\d+)\s*<\/PartNumber>\s*<ETag>\s*([^<]+)\s*<\/ETag>\s*<\/Part>/i', $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $parts = [];
        foreach ($matches as $match) {
            $etag = \html_entity_decode($match[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $parts[(int) $match[1]] = \trim($etag, " \t\n\r\0\x0B\"");
        }

        return $parts;
    }

    /**
     * @return array{0: Document, 1: string|null}
     */
    protected function completeMultipartUpload(callable $locks, Database $dbForProject, Cache $cache, Document $project, Device $deviceForFiles, Document $bucket, array $upload, array $selected): array
    {
        $parts = $this->getMultipartParts($upload);
        $partsByNumber = [];
        foreach ($parts as $part) {
            $partsByNumber[$part['partNumber']] = $part;
        }

        \ksort($selected);
        if ($selected === []) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Multipart upload has no parts.');
        }

        $size = 0;
        $etagBytes = '';
        $expected = 1;
        foreach ($selected as $partNumber => $submittedEtag) {
            if ($partNumber !== $expected || !isset($partsByNumber[$partNumber])) {
                throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Multipart parts must be contiguous and start at part 1.');
            }
            if (!\hash_equals($partsByNumber[$partNumber]['etag'], $submittedEtag)) {
                throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, "Multipart ETag does not match part {$partNumber}.");
            }
            $etagBytes .= \hex2bin($partsByNumber[$partNumber]['etag']) ?: '';
            $size += $partsByNumber[$partNumber]['size'];
            $expected++;
        }

        $this->validateFileConstraints($bucket, (string) ($upload['name'] ?? $upload['key']), $size);

        $etag = \md5($etagBytes) . '-' . \count($selected);
        $key = (string) ($upload['requestedKey'] ?? $upload['key'] ?? '');
        // Finalization and document replacement are serialized with ordinary PUTs for this key.
        $result = $locks($this->objectLockKey($project, $bucket, $key), 600, function () use ($dbForProject, $deviceForFiles, $bucket, &$upload, $selected, $size, $etag, $key): array {
            $path = (string) $upload['path'];
            $committed = $this->findObject($dbForProject, $bucket, $key);
            if ($committed !== null && $committed->getAttribute('path', '') === $path) {
                // A retry retained this upload's state after its unique staging path became live.
                return [$committed, null];
            }
            $cleanup = true;

            try {
                $upload['metadata']['device'] = $upload['metadata']['device'] ?? [];
                $chunks = \count($selected);
                if ($chunks === 1) {
                    $this->uploadEmptyMultipartFinalChunk($deviceForFiles, $upload);
                    $chunks = 2;
                }
                if (!$deviceForFiles->finalize($path, $chunks, $upload['metadata']['device'])) {
                    throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Failed to finalize multipart upload.');
                }

                $existing = $this->findObject($dbForProject, $bucket, $key);
                $previousPath = $existing?->getAttribute('path', '') ?? '';
                $cleanup = $previousPath !== $path;
                $file = $this->createObjectDocument(
                    $dbForProject,
                    $deviceForFiles,
                    $bucket,
                    (string) ($upload['folder'] ?? ''),
                    (string) ($upload['name'] ?? $upload['key']),
                    $existing?->getId() ?? (string) $upload['fileId'],
                    $path,
                    $etag,
                    (string) $upload['contentType'],
                    $size,
                    \count($selected),
                    $upload['metadata']['object'] ?? []
                );
                // The document owns this path now; later cleanup failures must never delete it.
                $cleanup = false;

                if ($previousPath !== '' && $previousPath !== $path) {
                    try {
                        $deviceForFiles->delete($previousPath);
                    } catch (\Throwable) {
                        // The replacement is already committed; old-path cleanup is best effort.
                    }
                }

                return [$file, $existing === null ? 'create' : 'update'];
            } catch (\Throwable $error) {
                if ($cleanup) {
                    $deviceForFiles->delete($path);
                }
                throw $error;
            }
        }, timeout: 120.0);
        $this->purgeMultipartUpload($cache, $project, $bucket, $upload);

        return $result;
    }

    protected function deleteMultipartUpload(Cache $cache, Document $project, Device $deviceForFiles, Document $bucket, array $upload): void
    {
        $path = (string) ($upload['path'] ?? '');
        if ($path !== '') {
            $deviceForFiles->abort($path, (string) ($upload['metadata']['device']['uploadId'] ?? ''));
        }
        $this->purgeMultipartUpload($cache, $project, $bucket, $upload);
    }

    protected function uploadEmptyMultipartFinalChunk(Device $deviceForFiles, array &$upload): void
    {
        $deviceForFiles->upload(
            new Stream(''),
            (string) $upload['path'],
            (string) ($upload['contentType'] ?? 'application/octet-stream'),
            2,
            2,
            $upload['metadata']['device']
        );
    }

    protected function purgeMultipartUpload(Cache $cache, Document $project, Document $bucket, array $upload): void
    {
        $uploadId = (string) ($upload['uploadId'] ?? '');
        if ($uploadId !== '') {
            $cache->purge($this->multipartStateKey($project, $uploadId));
            $cache->purge($this->multipartIndexKey($project, $bucket), $uploadId);
        }
    }

    protected function sendXml(Response $response, string $xml, int $status = Response::STATUS_CODE_OK): void
    {
        $response
            ->setStatusCode($status)
            ->setContentType('application/xml')
            ->send($xml);
    }

    protected function sendError(Response $response, string $code, string $message, string $resource, int $status = Response::STATUS_CODE_BAD_REQUEST): void
    {
        $response
            ->setStatusCode($status)
            ->setContentType('application/xml')
            ->send(S3Xml::error($code, $message, $resource));
    }

    private function mapError(AppwriteException $error): string
    {
        return match ($error->getType()) {
            AppwriteException::STORAGE_BUCKET_NOT_FOUND => 'NoSuchBucket',
            AppwriteException::STORAGE_FILE_NOT_FOUND => 'NoSuchKey',
            AppwriteException::STORAGE_BUCKET_ALREADY_EXISTS => 'BucketAlreadyOwnedByYou',
            AppwriteException::GENERAL_UNAUTHORIZED_SCOPE, AppwriteException::USER_UNAUTHORIZED => 'AccessDenied',
            default => 'InvalidRequest',
        };
    }

    private function mapStatus(AppwriteException $error): int
    {
        return match ($error->getType()) {
            AppwriteException::STORAGE_BUCKET_NOT_FOUND,
            AppwriteException::STORAGE_FILE_NOT_FOUND => Response::STATUS_CODE_NOT_FOUND,
            AppwriteException::STORAGE_BUCKET_ALREADY_EXISTS => Response::STATUS_CODE_CONFLICT,
            AppwriteException::GENERAL_UNAUTHORIZED_SCOPE,
            AppwriteException::USER_UNAUTHORIZED => Response::STATUS_CODE_FORBIDDEN,
            default => Response::STATUS_CODE_BAD_REQUEST,
        };
    }
}
