<?php

namespace Appwrite\Platform\Modules\S3\Http\S3;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\S3\Responses\S3Xml;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Distributed;
use Utopia\Platform\Action;
use Utopia\Storage\Device;

class Get extends Base
{
    public static function getName(): string
    {
        return 's3Get';
    }

    public function __construct()
    {
        parent::__construct(Action::HTTP_REQUEST_METHOD_GET);
    }

    protected function route(Request $request, Response $response, Database $dbForProject, Document $project, Document $team, User $user, Device $deviceForFiles, callable $locks, Cache $cache, Event $queueForEvents, DeletePublisher $publisherForDeletes, AuditPublisher $publisherForAudits, Event $queueForRealtime, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, EventProcessor $eventProcessor): void
    {
        $isHead = $request->getMethod() === Action::HTTP_REQUEST_METHOD_HEAD
            || \in_array($this->query($request, 'x-id'), ['HeadObject', 'HeadBucket'], true);
        [$bucketId, $key] = $this->parts($request);

        if ($bucketId === '') {
            $this->authorize($request, $project, $team, $user, ['buckets.read']);
            if ($isHead) {
                $response->send('');
                return;
            }

            $buckets = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('buckets', [Query::limit(1000)]));
            $this->sendXml($response, S3Xml::listBuckets($buckets));
            return;
        }

        if ($key === '') {
            $bucketOperation = $isHead
                || $this->hasQuery($request, 'acl')
                || $this->hasQuery($request, 'location')
                || $this->unsupportedSubresource($request);
            $this->authorize($request, $project, $team, $user, [$bucketOperation ? 'buckets.read' : 'files.read']);
            $bucket = $this->bucket($dbForProject, $bucketId);

            if ($isHead) {
                $response->send('');
                return;
            }

            if ($this->hasQuery($request, 'acl')) {
                $this->sendXml($response, S3Xml::accessControlPolicy());
                return;
            }
            if ($this->hasQuery($request, 'location')) {
                $this->sendXml($response, S3Xml::bucketLocation());
                return;
            }
            if ($this->unsupportedSubresource($request)) {
                $this->sendXml($response, S3Xml::error('NotImplemented', 'This S3 feature is not supported by Appwrite Storage.', $request->getURI()), Response::STATUS_CODE_NOT_IMPLEMENTED);
                return;
            }
            if ($this->hasQuery($request, 'uploads')) {
                $this->sendXml($response, S3Xml::listMultipartUploads($bucketId, $this->getMultipartUploads($cache, $project, $bucket)));
                return;
            }

            $files = $dbForProject->getAuthorization()->skip(fn () => \iterator_to_array($dbForProject->cursor('bucket_' . $bucket->getSequence(), [
                Query::orderAsc('$sequence'),
            ], batchSize: 1000), false));
            $this->assertUniqueObjectKeys($files);
            if ($this->query($request, 'list-type') === '2') {
                $this->sendXml($response, S3Xml::listObjectsV2(
                    $bucketId,
                    $files,
                    $this->query($request, 'prefix'),
                    $this->query($request, 'delimiter'),
                    (int) $this->query($request, 'max-keys', '1000'),
                    $this->query($request, 'continuation-token'),
                    $this->query($request, 'start-after')
                ));
                return;
            }

            $this->sendXml($response, S3Xml::listObjects(
                $bucketId,
                $files,
                $this->query($request, 'prefix'),
                $this->query($request, 'delimiter'),
                (int) $this->query($request, 'max-keys', '1000'),
                $this->query($request, 'marker')
            ));
            return;
        }

        $this->authorize($request, $project, $team, $user, ['files.read']);
        $bucket = $this->bucket($dbForProject, $bucketId);

        if ($this->query($request, 'uploadId') !== '') {
            $parts = $this->getMultipartParts($this->multipartUploadForBucket($cache, $project, $this->query($request, 'uploadId'), $bucketId, $key));
            $this->sendXml($response, S3Xml::listParts($bucketId, $key, $this->query($request, 'uploadId'), $parts));
            return;
        }

        $isAcl = $this->hasQuery($request, 'acl');
        $isUnsupported = $this->unsupportedSubresource($request);
        $ifNoneMatch = $request->getHeaderLine('if-none-match');
        $ifMatch = $request->getHeaderLine('if-match');
        // Keep the version's path alive until its final window is sent: PUT,
        // multipart completion and DELETE share this object lock.
        $locks($this->objectLockKey($project, $bucket, $key), 600, function (Distributed $lock) use ($dbForProject, $deviceForFiles, $bucket, $key, $isHead, $isAcl, $isUnsupported, $ifNoneMatch, $ifMatch, $request, $response): void {
            $file = $this->getObject($dbForProject, $bucket, $key);
            $body = null;
            $retried = false;
            $changedRepeatedly = false;
            while (true) {
                $path = $file->getAttribute('path', '');
                $size = (int) $file->getAttribute('sizeOriginal', 0);
                $range = $this->range($request, $size);
                $etag = '"' . $file->getAttribute('signature', '') . '"';

                if (
                    $isHead
                    || $isAcl
                    || $isUnsupported
                    || $ifNoneMatch === $etag
                    || ($ifMatch !== '' && $ifMatch !== $etag)
                    || $range === false
                ) {
                    break;
                }

                try {
                    $length = $range === null ? $size : $range[1] - $range[0] + 1;
                    $body = (string) $deviceForFiles->read($path, $range[0] ?? 0, \min(MAX_OUTPUT_CHUNK_SIZE, $length));
                    break;
                } catch (\Throwable $error) {
                    $latest = $this->getObject($dbForProject, $bucket, $key);
                    if ($latest->getAttribute('path', '') === $path) {
                        throw $error;
                    }
                    // One moved-path retry closes races with writes outside the S3 gateway.
                    if ($retried) {
                        $changedRepeatedly = true;
                        break;
                    }
                    $retried = true;
                    $file = $latest;
                }
            }

            if ($changedRepeatedly) {
                $this->sendXml($response, S3Xml::error('SlowDown', 'The object changed repeatedly while it was being read. Please retry.', $request->getURI()), Response::STATUS_CODE_SERVICE_UNAVAILABLE);
                return;
            }

            if ($isAcl) {
                $this->sendXml($response, S3Xml::accessControlPolicy());
                return;
            }
            if ($isUnsupported) {
                $this->sendXml($response, S3Xml::error('NotImplemented', 'This S3 feature is not supported by Appwrite Storage.', $request->getURI()), Response::STATUS_CODE_NOT_IMPLEMENTED);
                return;
            }

            $size = (int) $file->getAttribute('sizeOriginal', 0);
            $range = $this->range($request, $size);
            $etag = '"' . $file->getAttribute('signature', '') . '"';

            if ($ifNoneMatch === $etag) {
                $response->setStatusCode(Response::STATUS_CODE_NOT_MODIFIED)->send('');
                return;
            }
            if ($ifMatch !== '' && $ifMatch !== $etag) {
                $this->sendXml($response, S3Xml::error('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold.', $request->getURI()), Response::STATUS_CODE_PRECONDITION_FAILED);
                return;
            }

            if ($range === false) {
                $response->addHeader('Content-Range', 'bytes */' . $size);
                $this->sendXml($response, S3Xml::error('InvalidRange', 'The requested range is not satisfiable.', $request->getURI()), Response::STATUS_CODE_REQUESTED_RANGE_NOT_SATISFIABLE);
                return;
            }

            $response
                ->setContentType($file->getAttribute('mimeType', 'application/octet-stream'))
                ->addHeader('ETag', $etag)
                ->addHeader('Accept-Ranges', 'bytes');

            $lastModified = \strtotime($file->getUpdatedAt() ?: $file->getCreatedAt());
            if ($lastModified !== false) {
                $response->addHeader('Last-Modified', \gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            }

            $metadata = $file->getAttribute('metadata', []);
            foreach (($metadata['userMetadata'] ?? []) as $name => $value) {
                $response->addHeader('x-amz-meta-' . $name, (string) $value);
            }
            $sse = (string) ($metadata['serverSideEncryption'] ?? '');
            if ($sse !== '') {
                $response->addHeader('x-amz-server-side-encryption', $sse);
            }

            foreach ([
                'cacheControl' => 'Cache-Control',
                'contentDisposition' => 'Content-Disposition',
                'contentEncoding' => 'Content-Encoding',
                'contentLanguage' => 'Content-Language',
                'expires' => 'Expires',
            ] as $attribute => $header) {
                $value = (string) ($metadata[$attribute] ?? '');
                if ($value !== '') {
                    $response->addHeader($header, $value);
                }
            }

            foreach ([
                'response-cache-control' => 'Cache-Control',
                'response-content-disposition' => 'Content-Disposition',
                'response-content-encoding' => 'Content-Encoding',
                'response-content-language' => 'Content-Language',
                'response-expires' => 'Expires',
            ] as $parameter => $header) {
                $value = $this->query($request, $parameter);
                if ($value !== '') {
                    $response->setHeader($header, $value);
                }
            }

            $responseContentType = $this->query($request, 'response-content-type');
            if ($responseContentType !== '') {
                $response->setContentType($responseContentType);
            }

            if ($range !== null) {
                [$start, $end] = $range;
                $response
                    ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT)
                    ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                    ->addHeader('Content-Length', (string) ($end - $start + 1));
            } else {
                $response->addHeader('Content-Length', (string) $size);
            }

            if ($isHead) {
                $response->send('');
                return;
            }

            if (\strlen($body) === ($range === null ? $size : $range[1] - $range[0] + 1)) {
                $response->send($body);
                return;
            }

            $response->removeHeader('Content-Length');
            $offset = $range[0] ?? 0;
            $remaining = $range === null ? $size : $range[1] - $range[0] + 1;
            $started = false;
            try {
                do {
                    $length = \min(MAX_OUTPUT_CHUNK_SIZE, $remaining);
                    if (!$lock->refresh() || \strlen($body) !== $length) {
                        throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Object changed or download lock expired.');
                    }
                    $started = true;
                    $response->chunk($body, $length === $remaining);
                    $offset += $length;
                    $remaining -= $length;
                    if ($remaining > 0) {
                        unset($body);
                        $body = (string) $deviceForFiles->read($path, $offset, \min(MAX_OUTPUT_CHUNK_SIZE, $remaining));
                    }
                } while ($remaining > 0);
            } catch (\Throwable $error) {
                // Closing before the terminal chunk makes truncation visible to
                // clients; never append XML or replay an already-sent prefix.
                if ($started) {
                    $response->abort();
                }
                throw $error;
            }
        }, timeout: 120.0);
    }

    /**
     * @return array{0: int, 1: int}|false|null
     */
    private function range(Request $request, int $size): array|false|null
    {
        $header = \trim($request->getHeaderLine('range'));
        if ($header === '') {
            return null;
        }

        if (!\preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return false;
        }
        if ($size <= 0) {
            return false;
        }

        $start = $matches[1];
        $end = $matches[2];
        if ($start === '' && $end === '') {
            return false;
        }

        if ($start === '') {
            $suffix = (int) $end;
            if ($suffix <= 0) {
                return false;
            }

            return [\max(0, $size - $suffix), \max(0, $size - 1)];
        }

        $start = (int) $start;
        $end = $end === '' ? $size - 1 : (int) $end;

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, \min($end, $size - 1)];
    }
}
