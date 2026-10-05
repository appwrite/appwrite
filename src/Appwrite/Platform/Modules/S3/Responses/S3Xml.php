<?php

namespace Appwrite\Platform\Modules\S3\Responses;

use Utopia\Database\Document;

class S3Xml
{
    public static function error(string $code, string $message, string $resource = '', string $requestId = ''): string
    {
        return self::document('Error', [
            'Code' => $code,
            'Message' => $message,
            'Resource' => $resource,
            'RequestId' => $requestId ?: \bin2hex(\random_bytes(8)),
        ]);
    }

    /**
     * @param array<Document> $buckets
     */
    public static function listBuckets(array $buckets): string
    {
        $items = '';
        foreach ($buckets as $bucket) {
            $items .= self::element('Bucket', [
                'Name' => $bucket->getId(),
                'CreationDate' => self::date($bucket->getCreatedAt()),
            ]);
        }

        return self::rawDocument('ListAllMyBucketsResult', '<Buckets>' . $items . '</Buckets>');
    }

    public static function accessControlPolicy(): string
    {
        return self::rawDocument('AccessControlPolicy', self::element('Owner', [
            'ID' => 'appwrite',
            'DisplayName' => 'appwrite',
        ]) . '<AccessControlList>' . self::element('Grant', [
            'Grantee' => [
                'ID' => 'appwrite',
                'DisplayName' => 'appwrite',
            ],
            'Permission' => 'FULL_CONTROL',
        ]) . '</AccessControlList>');
    }

    public static function bucketLocation(string $region = 'us-east-1'): string
    {
        return self::rawDocument('LocationConstraint', $region === 'us-east-1' ? '' : self::escape($region));
    }

    public static function copyObject(string $etag, ?string $lastModified = null): string
    {
        return self::document('CopyObjectResult', [
            'LastModified' => self::date($lastModified),
            'ETag' => '"' . $etag . '"',
        ]);
    }

    public static function copyPart(string $etag, ?string $lastModified = null): string
    {
        return self::document('CopyPartResult', [
            'LastModified' => self::date($lastModified),
            'ETag' => '"' . $etag . '"',
        ]);
    }

    /**
     * @param array<int, string> $keys
     */
    public static function deleteObjects(array $keys, bool $quiet = false): string
    {
        $items = '';
        if (!$quiet) {
            foreach ($keys as $key) {
                $items .= self::element('Deleted', ['Key' => $key]);
            }
        }

        return self::rawDocument('DeleteResult', $items);
    }

    /**
     * @param array<int, array{key: string, uploadId: string, initiated: string}> $uploads
     */
    public static function listMultipartUploads(string $bucketId, array $uploads): string
    {
        $items = '';
        foreach ($uploads as $upload) {
            $items .= self::element('Upload', [
                'Key' => $upload['key'],
                'UploadId' => $upload['uploadId'],
                'Initiated' => self::date($upload['initiated']),
            ]);
        }

        return self::rawDocument('ListMultipartUploadsResult', self::element('Bucket', $bucketId) . self::element('IsTruncated', 'false') . $items);
    }

    /**
     * @param array<Document> $files
     */
    public static function listObjects(string $bucketId, array $files, string $prefix = '', string $delimiter = '', int $maxKeys = 1000, string $marker = ''): string
    {
        $maxKeys = \max(0, \min(1000, $maxKeys));
        $entries = [];
        $commonPrefixes = [];
        foreach ($files as $file) {
            $key = self::fileKey($file);
            if (($prefix !== '' && !\str_starts_with($key, $prefix)) || ($marker !== '' && \strcmp($key, $marker) <= 0)) {
                continue;
            }

            if ($delimiter !== '') {
                $rest = \substr($key, \strlen($prefix));
                $position = \strpos($rest, $delimiter);
                if ($position !== false) {
                    $commonPrefixes[$prefix . \substr($rest, 0, $position + \strlen($delimiter))] = true;
                    continue;
                }
            }

            $entries[$key] = ['type' => 'file', 'file' => $file];
        }

        foreach (\array_keys($commonPrefixes) as $commonPrefix) {
            if ($marker !== '' && \strcmp($commonPrefix, $marker) <= 0) {
                continue;
            }
            $entries[$commonPrefix] = ['type' => 'prefix', 'prefix' => $commonPrefix];
        }

        \ksort($entries, SORT_STRING);
        $selected = \array_slice($entries, 0, $maxKeys, true);
        $isTruncated = \count($entries) > $maxKeys;
        $lastKey = $selected === [] ? '' : (string) \array_key_last($selected);
        $items = '';
        foreach ($selected as $key => $entry) {
            if ($entry['type'] === 'prefix') {
                $items .= self::element('CommonPrefixes', ['Prefix' => (string) $entry['prefix']]);
                continue;
            }

            $file = $entry['file'];
            $items .= self::element('Contents', [
                'Key' => $key,
                'LastModified' => self::date($file->getUpdatedAt() ?: $file->getCreatedAt()),
                'ETag' => '"' . $file->getAttribute('signature', '') . '"',
                'Size' => (string) $file->getAttribute('sizeOriginal', 0),
                'StorageClass' => 'STANDARD',
            ]);
        }

        $body = self::elements([
            'Name' => $bucketId,
            'Prefix' => $prefix,
            'Marker' => $marker,
            'MaxKeys' => (string) $maxKeys,
            'IsTruncated' => $isTruncated ? 'true' : 'false',
        ]);
        if ($delimiter !== '') {
            $body .= self::element('Delimiter', $delimiter);
        }
        if ($isTruncated && $lastKey !== '') {
            $body .= self::element('NextMarker', $lastKey);
        }

        return self::rawDocument('ListBucketResult', $body . $items);
    }

    /**
     * @param array<Document> $files
     */
    public static function listObjectsV2(
        string $bucketId,
        array $files,
        string $prefix = '',
        string $delimiter = '',
        int $maxKeys = 1000,
        string $continuationToken = '',
        string $startAfter = ''
    ): string {
        $maxKeys = \max(0, \min(1000, $maxKeys));
        $after = $continuationToken !== '' ? self::decodeContinuationToken($continuationToken) : $startAfter;
        $entries = [];
        $commonPrefixes = [];

        foreach ($files as $file) {
            $key = self::fileKey($file);
            if ($prefix !== '' && !\str_starts_with($key, $prefix)) {
                continue;
            }
            if ($after !== '' && \strcmp($key, $after) <= 0) {
                continue;
            }

            if ($delimiter !== '') {
                $rest = \substr($key, \strlen($prefix));
                $position = \strpos($rest, $delimiter);
                if ($position !== false) {
                    $commonPrefixes[$prefix . \substr($rest, 0, $position + \strlen($delimiter))] = true;
                    continue;
                }
            }

            $entries[$key] = ['type' => 'file', 'file' => $file];
        }

        foreach (\array_keys($commonPrefixes) as $commonPrefix) {
            if ($after !== '' && \strcmp($commonPrefix, $after) <= 0) {
                continue;
            }
            $entries[$commonPrefix] = ['type' => 'prefix', 'prefix' => $commonPrefix];
        }

        // PHP casts numeric keys to int; without SORT_STRING they sort numerically,
        // which breaks the strcmp-based continuation filter and skips objects.
        \ksort($entries, SORT_STRING);

        $selected = \array_slice($entries, 0, $maxKeys, true);
        $isTruncated = \count($entries) > $maxKeys;
        $lastKey = $selected === [] ? '' : (string) \array_key_last($selected);

        $items = '';
        foreach ($selected as $entry) {
            if ($entry['type'] === 'prefix') {
                $items .= self::element('CommonPrefixes', [
                    'Prefix' => (string) $entry['prefix'],
                ]);
                continue;
            }

            $file = $entry['file'];
            $items .= self::element('Contents', [
                'Key' => self::fileKey($file),
                'LastModified' => self::date($file->getUpdatedAt() ?: $file->getCreatedAt()),
                'ETag' => '"' . $file->getAttribute('signature', '') . '"',
                'Size' => (string) $file->getAttribute('sizeOriginal', 0),
                'StorageClass' => 'STANDARD',
            ]);
        }

        $body = self::elements([
            'Name' => $bucketId,
            'Prefix' => $prefix,
            'KeyCount' => (string) \count($selected),
            'MaxKeys' => (string) $maxKeys,
            'IsTruncated' => $isTruncated ? 'true' : 'false',
        ]);

        if ($delimiter !== '') {
            $body .= self::element('Delimiter', $delimiter);
        }
        if ($continuationToken !== '') {
            $body .= self::element('ContinuationToken', $continuationToken);
        }
        if ($startAfter !== '') {
            $body .= self::element('StartAfter', $startAfter);
        }
        if ($isTruncated && $lastKey !== '') {
            $body .= self::element('NextContinuationToken', self::encodeContinuationToken($lastKey));
        }

        return self::rawDocument('ListBucketResult', $body . $items);
    }

    public static function initiateMultipart(string $bucketId, string $key, string $uploadId): string
    {
        return self::document('InitiateMultipartUploadResult', [
            'Bucket' => $bucketId,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
    }

    public static function completeMultipart(string $bucketId, string $key, string $etag): string
    {
        return self::document('CompleteMultipartUploadResult', [
            'Location' => '/' . $bucketId . '/' . $key,
            'Bucket' => $bucketId,
            'Key' => $key,
            'ETag' => '"' . $etag . '"',
        ]);
    }

    /**
     * @param array<int, array{partNumber: int, etag: string, size: int}> $parts
     */
    public static function listParts(string $bucketId, string $key, string $uploadId, array $parts): string
    {
        $items = '';
        foreach ($parts as $part) {
            $items .= self::element('Part', [
                'PartNumber' => (string) $part['partNumber'],
                'ETag' => '"' . $part['etag'] . '"',
                'Size' => (string) $part['size'],
            ]);
        }

        return self::rawDocument('ListPartsResult', self::elements([
            'Bucket' => $bucketId,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]) . $items);
    }

    private static function document(string $root, array $values): string
    {
        return self::rawDocument($root, self::elements($values));
    }

    private static function rawDocument(string $root, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . '<' . $root . '>' . $body . '</' . $root . '>';
    }

    private static function elements(array $values): string
    {
        $xml = '';
        foreach ($values as $key => $value) {
            $xml .= self::element((string) $key, \is_array($value) ? $value : (string) $value);
        }
        return $xml;
    }

    private static function element(string $name, string|array $value): string
    {
        $content = \is_array($value) ? self::elements($value) : self::escape($value);
        return '<' . $name . '>' . $content . '</' . $name . '>';
    }

    private static function fileKey(Document $file): string
    {
        return $file->getAttribute('folder', '') . $file->getAttribute('name', $file->getId());
    }

    private static function encodeContinuationToken(string $key): string
    {
        return \rtrim(\strtr(\base64_encode($key), '+/', '-_'), '=');
    }

    private static function decodeContinuationToken(string $token): string
    {
        $decoded = \base64_decode(\strtr($token, '-_', '+/'), true);
        return \is_string($decoded) ? $decoded : '';
    }

    private static function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private static function date(?string $date): string
    {
        $time = \strtotime($date ?? '');
        return \gmdate('Y-m-d\TH:i:s.000\Z', $time === false ? \time() : $time);
    }
}
