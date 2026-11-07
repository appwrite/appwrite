<?php

declare(strict_types=1);

namespace Tests\Unit\Transformation\Adapter;

use Appwrite\Transformation\Adapter\Analytics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyticsTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<string, string>, bool}>
     */
    public static function traits(): \Iterator
    {
        yield 'html' => [['content-type' => 'text/html'], true];
        yield 'html with charset' => [['Content-Type' => 'text/html; charset=utf-8'], true];
        yield 'mixed case' => [['content-type' => 'Text/HTML'], true];
        yield 'identity encoding' => [['content-type' => 'text/html', 'Content-Encoding' => 'identity'], true];
        yield 'gzip' => [['content-type' => 'text/html', 'content-encoding' => 'gzip'], false];
        yield 'brotli' => [['content-type' => 'text/html', 'Content-Encoding' => 'br'], false];
        yield 'json' => [['content-type' => 'application/json'], false];
        yield 'no content type' => [[], false];
    }

    /**
     * @param array<string, string> $traits
     */
    #[DataProvider('traits')]
    public function testIsValid(array $traits, bool $expected): void
    {
        $adapter = new Analytics('https://fra.cloud.appwrite.io/v1', 'project', 'property', '29.2.0');

        $this->assertSame($expected, $adapter->isValid($traits));
    }

    public function testTransform(): void
    {
        $adapter = new Analytics('https://fra.cloud.appwrite.io/v1', 'project', 'property', '29.2.0');
        $adapter->setInput('<html><body>Hello</body></html>');
        $adapter->transform();

        $this->assertSame(
            '<html><body>Hello</body></html><script defer src="https://sdk.appwrite.io/web/29.2.0/tracker.js" data-endpoint="https://fra.cloud.appwrite.io/v1" data-project="project" data-property="property"></script>',
            $adapter->getOutput()
        );
    }

    public function testTransformEscapes(): void
    {
        $adapter = new Analytics('https://example.com/v1', 'project', '"><script>alert(1)</script>', '29.2.0');
        $adapter->setInput('');
        $adapter->transform();

        $output = (string) $adapter->getOutput();
        $this->assertStringNotContainsString('<script>alert(1)', $output);
        $this->assertStringContainsString('data-property="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $output);
    }
}
