<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3\Responses;

use Appwrite\Platform\Modules\S3\Responses\S3Xml;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class S3XmlTest extends TestCase
{
    public function testListBucketsReturnsS3Xml(): void
    {
        $xml = S3Xml::listBuckets([
            new Document([
                '$id' => 'bucket-a',
                '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            ]),
        ]);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<ListAllMyBucketsResult>', $xml);
        $this->assertStringContainsString('<Name>bucket-a</Name>', $xml);
    }

    public function testListObjectsEscapesKeys(): void
    {
        $xml = S3Xml::listObjects('bucket-a', [
            new Document([
                '$id' => 'filea',
                '$createdAt' => '2026-01-01T00:00:00.000+00:00',
                'name' => 'folder/file&one.txt',
                'signature' => 'etag-a',
                'sizeOriginal' => 12,
            ]),
        ]);

        $this->assertStringContainsString('<Key>folder/file&amp;one.txt</Key>', $xml);
    }

    public function testListObjectsUsesFolderAndName(): void
    {
        $xml = S3Xml::listObjects('bucket-a', [
            $this->file('filea', 'file&one.txt', 'folder/'),
        ]);

        $this->assertStringContainsString('<Key>folder/file&amp;one.txt</Key>', $xml);
    }

    public function testListObjectsSupportsDelimiterAndMarkerPagination(): void
    {
        $firstPage = S3Xml::listObjects('bucket-a', [
            $this->file('filea', 'readme.txt', 'docs/'),
            $this->file('fileb', 'a.txt', 'photos/2025/'),
            $this->file('filec', 'b.txt', 'photos/2026/'),
        ], delimiter: '/', maxKeys: 1);

        $this->assertStringContainsString('<CommonPrefixes><Prefix>docs/</Prefix></CommonPrefixes>', $firstPage);
        $this->assertStringContainsString('<IsTruncated>true</IsTruncated>', $firstPage);
        $this->assertStringContainsString('<NextMarker>docs/</NextMarker>', $firstPage);

        $secondPage = S3Xml::listObjects('bucket-a', [
            $this->file('filea', 'readme.txt', 'docs/'),
            $this->file('fileb', 'a.txt', 'photos/2025/'),
            $this->file('filec', 'b.txt', 'photos/2026/'),
        ], delimiter: '/', maxKeys: 1, marker: 'docs/');

        $this->assertStringNotContainsString('<Prefix>docs/</Prefix>', $secondPage);
        $this->assertStringContainsString('<CommonPrefixes><Prefix>photos/</Prefix></CommonPrefixes>', $secondPage);
        $this->assertStringContainsString('<IsTruncated>false</IsTruncated>', $secondPage);
    }

    public function testListObjectsV2SupportsPrefixPaginationAndDelimiter(): void
    {
        $xml = S3Xml::listObjectsV2('bucket-a', [
            $this->file('filea', 'readme.txt', 'docs/'),
            $this->file('fileb', 'c.txt', 'photos/2025/'),
            $this->file('filec', 'a.txt', 'photos/2026/'),
            $this->file('filed', 'b.txt', 'photos/2026/'),
        ], prefix: 'photos/', delimiter: '/', maxKeys: 1);

        $this->assertStringContainsString('<ListBucketResult>', $xml);
        $this->assertStringContainsString('<Prefix>photos/</Prefix>', $xml);
        $this->assertStringContainsString('<KeyCount>1</KeyCount>', $xml);
        $this->assertStringContainsString('<MaxKeys>1</MaxKeys>', $xml);
        $this->assertStringContainsString('<IsTruncated>true</IsTruncated>', $xml);
        $this->assertStringContainsString('<CommonPrefixes><Prefix>photos/2025/</Prefix></CommonPrefixes>', $xml);
        $this->assertStringContainsString('<NextContinuationToken>', $xml);
    }

    public function testListObjectsV2ContinuationTokenStartsAfterLastKey(): void
    {
        $firstPage = S3Xml::listObjectsV2('bucket-a', [
            $this->file('filea', 'a.txt'),
            $this->file('fileb', 'b.txt'),
        ], maxKeys: 1);

        preg_match('/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $firstPage, $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $secondPage = S3Xml::listObjectsV2('bucket-a', [
            $this->file('filea', 'a.txt'),
            $this->file('fileb', 'b.txt'),
        ], continuationToken: $matches[1]);

        $this->assertStringNotContainsString('<Key>a.txt</Key>', $secondPage);
        $this->assertStringContainsString('<Key>b.txt</Key>', $secondPage);
        $this->assertStringContainsString('<IsTruncated>false</IsTruncated>', $secondPage);
    }

    public function testListObjectsV2OrdersAndPaginatesNumericKeysLexicographically(): void
    {
        $files = [
            $this->file('filea', '9'),
            $this->file('fileb', '10'),
        ];

        $firstPage = S3Xml::listObjectsV2('bucket-a', $files, maxKeys: 1);
        $this->assertStringContainsString('<Key>10</Key>', $firstPage);
        $this->assertStringNotContainsString('<Key>9</Key>', $firstPage);
        $this->assertStringContainsString('<IsTruncated>true</IsTruncated>', $firstPage);

        preg_match('/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $firstPage, $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $secondPage = S3Xml::listObjectsV2('bucket-a', $files, maxKeys: 1, continuationToken: $matches[1]);
        $this->assertStringContainsString('<Key>9</Key>', $secondPage);
        $this->assertStringContainsString('<IsTruncated>false</IsTruncated>', $secondPage);
    }

    public function testMultipartResponses(): void
    {
        $init = S3Xml::initiateMultipart('bucket-a', 'object.txt', 'upload-id');
        $parts = S3Xml::listParts('bucket-a', 'object.txt', 'upload-id', [
            ['partNumber' => 1, 'etag' => 'etag-1', 'size' => 10],
        ]);
        $complete = S3Xml::completeMultipart('bucket-a', 'object.txt', 'final-etag');

        $this->assertStringContainsString('<UploadId>upload-id</UploadId>', $init);
        $this->assertStringContainsString('<PartNumber>1</PartNumber>', $parts);
        $this->assertStringContainsString('<ETag>&quot;final-etag&quot;</ETag>', $complete);
    }

    public function testAccessControlPolicyRendersNestedGrantee(): void
    {
        $xml = S3Xml::accessControlPolicy();

        $this->assertStringContainsString('<Owner><ID>appwrite</ID><DisplayName>appwrite</DisplayName></Owner>', $xml);
        $this->assertStringContainsString('<Grantee><ID>appwrite</ID><DisplayName>appwrite</DisplayName></Grantee>', $xml);
        $this->assertStringContainsString('<Permission>FULL_CONTROL</Permission>', $xml);
        $this->assertStringNotContainsString('<Grantee>Array</Grantee>', $xml);
    }

    private function file(string $id, string $name, string $folder = ''): Document
    {
        return new Document([
            '$id' => $id,
            '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            'name' => $name,
            'folder' => $folder,
            'signature' => 'etag-' . $id,
            'sizeOriginal' => 12,
        ]);
    }
}
