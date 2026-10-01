<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Network\Validator\PublicURL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destination\PublicInternet;

final class PublicURLTest extends TestCase
{
    public function testAcceptsPublicAddresses(): void
    {
        $validator = $this->validator();

        $this->assertTrue($validator->isValid('https://1.1.1.1/'));
        $this->assertTrue($validator->isValid('http://[2606:4700:4700::1111]/path'));
        $this->assertTrue($validator->isValid('https://example.com/favicon.ico'));
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsNonPublicUrls(string $url, string $reason): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isValid($url), "Expected {$url} to be rejected");
        $this->assertStringContainsString($reason, $validator->getDescription());
    }

    public static function rejectedUrls(): \Iterator
    {
        yield 'not a url' => ['unknown-address', 'valid URL'];
        yield 'no scheme' => ['example.com/path', 'valid URL'];
        yield 'no host' => ['http:///path', 'valid URL'];
        yield 'ftp scheme' => ['ftp://example.com/', 'valid URL'];
        yield 'file scheme' => ['file:///etc/passwd', 'valid URL'];
        yield 'gopher scheme' => ['gopher://example.com/x', 'valid URL'];
        yield 'localhost' => ['http://localhost/', 'not a known public domain'];
        yield 'container name' => ['http://appwrite-mariadb:3306/', 'not a known public domain'];
        yield 'unknown tld' => ['http://unknown-address.test/', 'not a known public domain'];
        yield 'numeric host' => ['http://2852039166/', 'not a known public domain'];
        yield 'loopback' => ['http://127.0.0.1/', 'private or reserved'];
        yield 'imds' => ['http://169.254.169.254/latest/meta-data/', 'private or reserved'];
        yield 'private' => ['http://10.0.0.5:8080/', 'private or reserved'];
        yield 'cgnat' => ['http://100.64.0.1/x', 'private or reserved'];
        yield 'loopback v6' => ['http://[::1]/', 'private or reserved'];
        yield 'ipv4-mapped' => ['http://[::ffff:127.0.0.1]/', 'private or reserved'];
        yield '6to4 imds' => ['http://[2002:a9fe:a9fe::]/x', 'private or reserved'];
        yield 'resolves to imds' => ['http://rebind.example.com/', '169.254.169.254'];
        yield 'does not resolve' => ['http://nowhere.example.com/', 'does not resolve'];
    }

    public function testDescriptionResetsBetweenCalls(): void
    {
        $validator = $this->validator();

        $validator->isValid('http://127.0.0.1/');
        $validator->isValid('unknown-address');

        $this->assertStringNotContainsString('127.0.0.1', $validator->getDescription());
    }

    private function validator(): PublicURL
    {
        return new PublicURL(new PublicHostname(new PublicInternet(), new FixedLookup([
            'example.com' => ['93.184.215.14'],
            'rebind.example.com' => ['93.184.215.14', '169.254.169.254'],
        ])));
    }
}
