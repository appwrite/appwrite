<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Domain;

final class DomainTest extends TestCase
{
    public function testFoldsCaseToCanonicalForm(): void
    {
        $this->assertSame('app.example.com', Domain::validate('APP.Example.CoM'));
        $this->assertSame('app.example.com', Domain::validate('app.example.com'));
    }

    /** @return \Iterator<string, array<int, string>> */
    public static function malformed(): \Iterator
    {
        yield 'empty' => [''];
        yield 'scheme' => ['https://app.example.com'];
        yield 'port' => ['app.example.com:443'];
        yield 'path' => ['app.example.com/certs'];
        yield 'trailing slash' => ['app.example.com/'];
        yield 'underscore' => ['app_example.com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testRejectsMalformedDomains(string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Domain::validate($domain);
    }

    /** @return \Iterator<string, array<int, string>> */
    public static function dotSegmentPaths(): \Iterator
    {
        yield 'parent at the start' => ['/../../service/service-id/purge_all'];
        yield 'parent in the middle' => ['/assets/../admin'];
        yield 'parent at the end' => ['/assets/..'];
        yield 'current directory' => ['/assets/./app.js'];
        yield 'parent before a query' => ['/..?x=1'];
        yield 'encoded parent' => ['/%2e%2e/purge_all'];
        yield 'encoded parent, upper case' => ['/%2E%2E/purge_all'];
        yield 'half-encoded parent' => ['/.%2e/purge_all'];
        yield 'encoded current directory' => ['/%2e/app.js'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dotSegmentPaths')]
    public function testRejectsPathsWithDotSegments(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Domain::validatePaths([$path]);
    }

    public function testAcceptsDotsThatAreNotSegments(): void
    {
        $paths = [
            '/app.js',
            '/.well-known/security.txt',
            '/..png',
            '/a..b/c',
            '/...',
            '/redirect?to=../login',
            '/page#../section',
        ];

        $this->assertSame($paths, Domain::validatePaths($paths));
    }
}
