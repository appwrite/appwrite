<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn;

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
}
