<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicURL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicURLTest extends TestCase
{
    public function testAcceptsPublicIpLiterals(): void
    {
        $validator = new PublicURL();

        $this->assertTrue($validator->isValid('https://1.1.1.1/'));
        $this->assertTrue($validator->isValid('http://[2606:4700:4700::1111]/path'));
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsNonPublicUrls(string $url, string $reason): void
    {
        $validator = new PublicURL();

        $this->assertFalse($validator->isValid($url), "Expected {$url} to be rejected");
        $this->assertStringContainsString($reason, $validator->getDescription());
    }

    public static function rejectedUrls(): \Iterator
    {
        yield 'not a url' => ['unknown-address', 'valid URL'];
        yield 'ftp scheme' => ['ftp://example.com/', 'valid URL'];
        yield 'localhost' => ['http://localhost/', 'not a known public domain'];
        yield 'container name' => ['http://appwrite-mariadb:3306/', 'not a known public domain'];
        yield 'unknown tld' => ['http://unknown-address.test/', 'not a known public domain'];
        yield 'numeric host' => ['http://2852039166/', 'not a known public domain'];
        yield 'loopback' => ['http://127.0.0.1/', 'private or reserved'];
        yield 'imds' => ['http://169.254.169.254/latest/meta-data/', 'private or reserved'];
        yield 'private' => ['http://10.0.0.5:8080/', 'private or reserved'];
        yield 'loopback v6' => ['http://[::1]/', 'private or reserved'];
        yield 'ipv4-mapped' => ['http://[::ffff:127.0.0.1]/', 'private or reserved'];
    }

    public function testResolveIsEmptyForIpLiteralsAndRejections(): void
    {
        $validator = new PublicURL();

        $this->assertTrue($validator->isValid('https://1.1.1.1/'));
        $this->assertSame([], $validator->getResolve());

        $this->assertFalse($validator->isValid('http://127.0.0.1/'));
        $this->assertSame([], $validator->getResolve());
    }

    public function testDescriptionResetsBetweenCalls(): void
    {
        $validator = new PublicURL();

        $validator->isValid('http://127.0.0.1/');
        $validator->isValid('unknown-address');

        $this->assertStringNotContainsString('127.0.0.1', $validator->getDescription());
    }
}
