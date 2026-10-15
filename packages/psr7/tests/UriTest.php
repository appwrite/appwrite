<?php

declare(strict_types=1);

namespace Utopia\Psr7\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Psr7\Uri;

final class UriTest extends TestCase
{
    public function testToStringWithRootlessPathAndAuthority(): void
    {
        $uri = new Uri('http', '', 'example.com', null, 'foo/bar', '', '');
        $serialized = (string) $uri;

        $this->assertSame('http://example.com/foo/bar', $serialized);
        $this->assertSame($serialized, (string) Uri::parse($serialized));
    }

    public function testToStringWithDoubleSlashPathAndNoAuthority(): void
    {
        $uri = new Uri('', '', '', null, '//foo/bar', '', '');
        $serialized = (string) $uri;

        $this->assertSame('/foo/bar', $serialized);
        $this->assertSame($serialized, (string) Uri::parse($serialized));
    }

}
