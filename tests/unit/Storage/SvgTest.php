<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Appwrite\Storage\Svg;
use PHPUnit\Framework\TestCase;

final class SvgTest extends TestCase
{
    public function testKeepsBenignContent(): void
    {
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#FD366E"/><circle cx="60" cy="60" r="30" fill="#FFFFFF"/></svg>');

        $this->assertNotNull($clean);
        $this->assertStringContainsString('<rect', $clean);
        $this->assertStringContainsString('<circle', $clean);
        $this->assertStringContainsString('120', $clean);
    }

    public function testStripsScriptsAndEventHandlers(): void
    {
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(document.cookie)</script><rect onclick="alert(2)" width="10" height="10"/></svg>');

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onload', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $clean);
        $this->assertStringNotContainsStringIgnoringCase('alert', $clean);
    }

    public function testStripsJavascriptHref(): void
    {
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><circle r="5"/></a></svg>');

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
    }

    public function testStripsDoctypeAndExternalEntities(): void
    {
        // XXE: a resolved entity would embed /etc/passwd into the rendered text.
        $clean = Svg::sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" width="200" height="80"><text x="5" y="40">&xxe;</text></svg>');

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $clean);
        $this->assertStringNotContainsStringIgnoringCase('ENTITY', $clean);
        $this->assertStringNotContainsString('/etc/passwd', $clean);
    }

    public function testStripsRemoteImageReference(): void
    {
        // SSRF: an <image> pointing at the cloud metadata endpoint.
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="200" height="200"><image width="200" height="200" xlink:href="http://169.254.169.254/latest/meta-data/"/></svg>');

        $this->assertNotNull($clean);
        // The remote target is gone; the only http:// left is the SVG namespace
        // URI, which is an identifier and is never dereferenced.
        $this->assertStringNotContainsString('169.254.169.254', $clean);
        $this->assertStringNotContainsStringIgnoringCase('xlink:href', $clean);
    }

    public function testStripsLocalFileReference(): void
    {
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="file:///etc/passwd"/></svg>');

        $this->assertNotNull($clean);
        $this->assertStringNotContainsStringIgnoringCase('file:', $clean);
        $this->assertStringNotContainsString('/etc/passwd', $clean);
    }

    public function testKeepsInDocumentFragmentAndDataImage(): void
    {
        $clean = Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="#anchor"><circle r="5"/></a><image xlink:href="data:image/png;base64,AAAA"/></svg>');

        $this->assertNotNull($clean);
        $this->assertStringContainsString('#anchor', $clean);
        $this->assertStringContainsString('data:image/png', $clean);
    }
}
