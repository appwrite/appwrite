<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicURL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Validator\Allowlist;
use Utopia\Validator\Subnet;

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
        yield 'ipv4-compatible' => ['http://[::7f00:1]/', 'private or reserved'];
        yield 'ipv4-compatible link-local' => ['http://[::a9fe:a9fe]/', 'private or reserved'];
        yield 'site-local' => ['http://[fec0::1]/', 'private or reserved'];
        yield 'userinfo' => ['http://1.1.1.1@127.0.0.1/', 'must not contain credentials'];
        yield 'public userinfo' => ['http://user:secret@1.1.1.1/', 'must not contain credentials'];
        yield 'empty userinfo' => ['http://@1.1.1.1/', 'must not contain credentials'];
        yield 'backslash' => ['http://1.1.1.1/a\\b', 'must not contain credentials or backslashes'];
    }

    public function testResolveIsEmptyForIpLiteralsAndRejections(): void
    {
        $validator = new PublicURL();

        $this->assertTrue($validator->isValid('https://1.1.1.1/'));
        $this->assertSame([], $validator->getResolve());

        $this->assertFalse($validator->isValid('http://127.0.0.1/'));
        $this->assertSame([], $validator->getResolve());
    }

    public function testAcceptsAddressInAllowedSubnet(): void
    {
        $validator = new PublicURL(new Allowlist(subnets: [new Subnet('10.0.0.0/8'), new Subnet('fd00::/8')]));

        $this->assertTrue($validator->isValid('http://10.0.0.5/v1'), $validator->getDescription());
        $this->assertTrue($validator->isValid('http://[fd12::1]:8080/v1'), $validator->getDescription());
        $this->assertFalse($validator->isValid('http://192.168.1.1/v1'));
        $this->assertFalse($validator->isValid('http://[fe80::1]/v1'));
    }

    public function testAcceptsAllowedHostnameThatIsNotAKnownDomain(): void
    {
        $validator = new PublicURL(new Allowlist(['appwrite']));

        $this->assertTrue($validator->isValid('http://appwrite/v1'), $validator->getDescription());
        $this->assertTrue($validator->isValid('http://APPWRITE./v1'), $validator->getDescription());
        $this->assertFalse($validator->isValid('http://appwritex/v1'));
    }

    public function testAllowedHostnameDoesNotMatchLookalikes(): void
    {
        $validator = new PublicURL(new Allowlist(['example.invalid']));

        $this->assertTrue($validator->isValid('http://example.invalid/v1'), $validator->getDescription());
        $this->assertFalse($validator->isValid('http://evil-example.invalid/v1'));
        $this->assertFalse($validator->isValid('http://db.example.invalid/v1'));
        $this->assertFalse($validator->isValid('http://example.invalid.evil.invalid/v1'));
    }

    #[DataProvider('numericHosts')]
    public function testRefusesNumericHostsInsideAllowedSubnet(string $url): void
    {
        $validator = new PublicURL(new Allowlist(subnets: [new Subnet('127.0.0.0/8')]));

        $this->assertFalse($validator->isValid($url), "Expected {$url} to be rejected");
    }

    public static function numericHosts(): \Iterator
    {
        yield 'decimal' => ['http://2130706433/'];
        yield 'octal' => ['http://0177.0.0.1/'];
        yield 'shortened' => ['http://127.1/'];
    }

    #[DataProvider('refusedDespiteAllowlist')]
    public function testAllowlistKeepsSchemeAndCredentialChecks(string $url, string $reason): void
    {
        $validator = new PublicURL(new Allowlist(['appwrite'], [new Subnet('10.0.0.0/8')]));

        $this->assertFalse($validator->isValid($url), "Expected {$url} to be rejected");
        $this->assertStringContainsString($reason, $validator->getDescription());
    }

    public static function refusedDespiteAllowlist(): \Iterator
    {
        yield 'userinfo' => ['http://user@10.0.0.5/', 'must not contain credentials'];
        yield 'userinfo hostname' => ['http://user@appwrite/', 'must not contain credentials'];
        yield 'backslash' => ['http://10.0.0.5/a\\b', 'must not contain credentials or backslashes'];
        yield 'gopher scheme' => ['gopher://10.0.0.5/', 'valid URL'];
    }

    #[RunInSeparateProcess]
    public function testAcceptsHostnameResolvingIntoAllowedSubnetInsideCoroutine(): void
    {
        $validator = new PublicURL(new Allowlist(subnets: [new Subnet('127.0.0.0/8'), new Subnet('::1')]));
        $valid = null;

        $this->inHookedCoroutine(function () use ($validator, &$valid): void {
            $valid = $validator->isValid('http://localhost/');
        });

        $this->assertTrue($valid, $validator->getDescription());
        $this->assertNotSame([], $validator->getResolve());
    }

    #[RunInSeparateProcess]
    public function testRejectsHostnameResolvingOutsideAllowedSubnetInsideCoroutine(): void
    {
        $validator = new PublicURL(new Allowlist(subnets: [new Subnet('10.0.0.0/8')]));
        $valid = null;

        $this->inHookedCoroutine(function () use ($validator, &$valid): void {
            $valid = $validator->isValid('http://localhost/');
        });

        $this->assertFalse($valid);
    }

    public function testDescriptionResetsBetweenCalls(): void
    {
        $validator = new PublicURL();

        $validator->isValid('http://127.0.0.1/');
        $validator->isValid('unknown-address');

        $this->assertStringNotContainsString('127.0.0.1', $validator->getDescription());
    }

    private function inHookedCoroutine(callable $callback): void
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        Coroutine\run($callback);
    }
}
