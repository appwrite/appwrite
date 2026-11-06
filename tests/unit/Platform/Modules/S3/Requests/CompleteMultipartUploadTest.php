<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3\Requests;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\S3\Requests\CompleteMultipartUpload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompleteMultipartUploadTest extends TestCase
{
    public function testParsesGoSdkMemberOrder(): void
    {
        // AWS SDK for Go v2 serializes members alphabetically and escapes quotes as &#34;.
        $body = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Part><ChecksumCRC32>AAAAAA==</ChecksumCRC32><ETag>&#34;e304da715b5366de9bd1b23f5964b2c7&#34;</ETag><PartNumber>1</PartNumber></Part>'
            . '<Part><ETag>&#34;9284947f808255bfd255111ff8089af4&#34;</ETag><PartNumber>2</PartNumber></Part>'
            . '</CompleteMultipartUpload>';

        $this->assertSame([
            1 => 'e304da715b5366de9bd1b23f5964b2c7',
            2 => '9284947f808255bfd255111ff8089af4',
        ], CompleteMultipartUpload::parts($body));
    }

    public function testParsesPartNumberFirstOrder(): void
    {
        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<CompleteMultipartUpload>\n"
            . "  <Part>\n    <PartNumber>2</PartNumber>\n    <ETag>\"bbb\"</ETag>\n  </Part>\n"
            . "  <Part>\n    <PartNumber>1</PartNumber>\n    <ETag>&quot;aaa&quot;</ETag>\n  </Part>\n"
            . "</CompleteMultipartUpload>\n";

        $this->assertSame([2 => 'bbb', 1 => 'aaa'], CompleteMultipartUpload::parts($body));
    }

    public function testEmptyBodyHasNoParts(): void
    {
        $this->assertSame([], CompleteMultipartUpload::parts(''));
        $this->assertSame([], CompleteMultipartUpload::parts('<CompleteMultipartUpload/>'));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function malformedBodies(): \Iterator
    {
        yield 'not xml' => ['<CompleteMultipartUpload><Part>'];
        yield 'missing etag' => ['<CompleteMultipartUpload><Part><PartNumber>1</PartNumber></Part></CompleteMultipartUpload>'];
        yield 'missing part number' => ['<CompleteMultipartUpload><Part><ETag>"aaa"</ETag></Part></CompleteMultipartUpload>'];
        yield 'non-numeric part number' => ['<CompleteMultipartUpload><Part><PartNumber>one</PartNumber><ETag>"aaa"</ETag></Part></CompleteMultipartUpload>'];
        yield 'doctype' => ['<!DOCTYPE CompleteMultipartUpload [<!ENTITY e "aaa">]><CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>&e;</ETag></Part></CompleteMultipartUpload>'];
    }

    #[DataProvider('malformedBodies')]
    public function testRejectsMalformedBodies(string $body): void
    {
        $this->expectException(AppwriteException::class);

        CompleteMultipartUpload::parts($body);
    }
}
