<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7;

use PHPUnit\Framework\TestCase;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

final class ConstantsTest extends TestCase
{
    public function testItProvidesHttpMethodConstants(): void
    {
        $this->assertSame('GET', Method::GET);
        $this->assertSame('POST', Method::POST);
        $this->assertSame('PUT', Method::PUT);
        $this->assertSame('PATCH', Method::PATCH);
        $this->assertSame('DELETE', Method::DELETE);
        $this->assertSame('HEAD', Method::HEAD);
        $this->assertSame('OPTIONS', Method::OPTIONS);
        $this->assertSame('CONNECT', Method::CONNECT);
        $this->assertSame('TRACE', Method::TRACE);
    }

    public function testItProvidesCommonHeaderConstants(): void
    {
        $this->assertSame('Accept', Header::ACCEPT);
        $this->assertSame('Authorization', Header::AUTHORIZATION);
        $this->assertSame('Content-Disposition', Header::CONTENT_DISPOSITION);
        $this->assertSame('Content-Type', Header::CONTENT_TYPE);
        $this->assertSame('Host', Header::HOST);
        $this->assertSame('User-Agent', Header::USER_AGENT);
    }

    public function testItProvidesCommonContentTypeConstants(): void
    {
        $this->assertSame('application/json', ContentType::JSON);
        $this->assertSame('application/merge-patch+json', ContentType::MERGE_PATCH_JSON);
        $this->assertSame('application/x-www-form-urlencoded', ContentType::FORM_URLENCODED);
        $this->assertSame('multipart/form-data', ContentType::MULTIPART_FORM_DATA);
        $this->assertSame('application/octet-stream', ContentType::OCTET_STREAM);
        $this->assertSame('text/plain', ContentType::PLAIN_TEXT);
        $this->assertSame('application/xml', ContentType::XML);
    }
}
