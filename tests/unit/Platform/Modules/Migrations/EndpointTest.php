<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Endpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;

final class EndpointTest extends TestCase
{
    public function testPinsAPublicHostToTheAddressItWasCheckedAgainst(): void
    {
        $endpoint = $this->endpoint();

        $this->assertSame(['example.com:443:93.184.215.14'], $endpoint->resolve('https://example.com/v1'));
        $this->assertSame(['example.com:80:93.184.215.14'], $endpoint->resolve('http://example.com/v1/users?limit=1'));
        $this->assertSame(['example.com:8443:93.184.215.14'], $endpoint->resolve('https://example.com:8443/v1'));
        $this->assertSame(['ipv6.example.com:443:[2606:4700:4700::1111]'], $endpoint->resolve('https://ipv6.example.com/v1'));
    }

    public function testPinsBothSpellingsOfAHostWithATrailingDot(): void
    {
        $this->assertSame(
            ['example.com.:443:93.184.215.14', 'example.com:443:93.184.215.14'],
            $this->endpoint()->resolve('https://example.com./v1'),
        );
    }

    public function testDoesNotPinAPublicAddress(): void
    {
        $endpoint = $this->endpoint();

        $this->assertSame([], $endpoint->resolve('https://1.1.1.1/v1'));
        $this->assertSame([], $endpoint->resolve('https://[2606:4700:4700::1111]/v1'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'link-local address' => ['http://169.254.169.254/v1', '169.254.169.254'];
        yield 'loopback address' => ['http://127.0.0.1/v1', '127.0.0.1'];
        yield 'IPv6 loopback address' => ['http://[::1]/v1', '::1'];
        yield 'host resolving to a private address' => ['https://rebind.example.com/v1', '10.0.0.1'];
        yield 'host that does not resolve' => ['https://missing.example.com/v1', 'does not resolve'];
        yield 'lookalike of a host allowed by name' => ['http://appwrite.example.com/v1', '10.0.0.1'];
        yield 'gopher' => ['gopher://example.com/', 'schemes (http, https)'];
        yield 'file' => ['file:///etc/passwd', 'schemes (http, https)'];
        yield 'not a URL' => ['example.com', 'schemes (http, https)'];
        yield 'credentials' => ['https://user:pass@example.com/v1', 'credentials'];
        yield 'empty credentials' => ['https://@example.com/v1', 'credentials'];
        yield 'backslash' => ['https://example.com\\@169.254.169.254/v1', 'schemes (http, https)'];
    }

    #[DataProvider('refused')]
    public function testRefusesAnEndpointOutsideThePolicy(string $url, string $reason): void
    {
        $endpoint = $this->endpoint('', 'appwrite');

        foreach ([$endpoint->resolve(...), $endpoint->validate(...)] as $check) {
            try {
                $check($url);
                $this->fail("Accepted {$url}");
            } catch (Exception $exception) {
                $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $exception->getType());
                $this->assertStringStartsWith('Invalid `endpoint`: ', $exception->getMessage());
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function testAllowedRangeAdmitsAPrivateAddress(): void
    {
        $endpoint = $this->endpoint('', '172.16.238.0/24');

        $this->assertSame(['appwrite.test:80:172.16.238.10'], $endpoint->resolve('http://appwrite.test/v1'));
        $this->assertSame([], $endpoint->resolve('http://172.16.238.10/v1'));

        $this->expectException(Exception::class);
        $endpoint->resolve('http://127.0.0.1/v1');
    }

    public function testHostAllowedByNameIsNotPinned(): void
    {
        $endpoint = $this->endpoint('', ' Appwrite. ');

        $this->assertSame([], $endpoint->resolve('http://appwrite/v1'));
        $this->assertSame([], $endpoint->resolve('http://APPWRITE./v1'));
        $endpoint->validate('http://appwrite/v1');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function allowedByOneList(): iterable
    {
        yield 'internal address' => ['127.0.0.1', '', 'http://127.0.0.1/v1'];
        yield 'internal IPv6 address' => ['::1', '', 'http://[::1]/v1'];
        yield 'internal range' => ['10.0.0.0/8', '', 'https://rebind.example.com/v1'];
        yield 'migrations address' => ['', '127.0.0.1', 'http://127.0.0.1/v1'];
        yield 'migrations range' => ['', '10.0.0.0/8', 'https://rebind.example.com/v1'];
        yield 'migrations IPv6 range' => ['', 'fd00::/8', 'http://[fd12::1]/v1'];
        yield 'migrations hostname' => ['', 'appwrite', 'http://appwrite/v1'];
    }

    #[DataProvider('allowedByOneList')]
    public function testEntryFromEitherListIsAllowed(string $internal, string $migrations, string $url): void
    {
        $this->endpoint($internal, $migrations)->validate($url);

        $this->expectException(Exception::class);
        $this->endpoint()->validate($url);
    }

    public function testBothListsApplyAtOnce(): void
    {
        $endpoint = $this->endpoint('172.16.238.0/24, 127.0.0.1', 'appwrite, 10.0.0.0/8');

        $this->assertSame(['appwrite.test:80:172.16.238.10'], $endpoint->resolve('http://appwrite.test/v1'));
        $this->assertSame([], $endpoint->resolve('http://127.0.0.1/v1'));
        $this->assertSame([], $endpoint->resolve('http://appwrite/v1'));
        $this->assertSame(['rebind.example.com:443:93.184.215.14'], $endpoint->resolve('https://rebind.example.com/v1'));

        foreach (['http://169.254.169.254/v1', 'http://[::1]/v1', 'http://192.168.1.1/v1'] as $url) {
            try {
                $endpoint->validate($url);
                $this->fail("Accepted {$url}");
            } catch (Exception $exception) {
                $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $exception->getType());
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'internal range' => ['10.0.0.0/33', ''];
        yield 'internal hostname next to an address' => ['172.16.238.0/24, appwrite', ''];
        yield 'migrations range' => ['', '10.0.0.0/33'];
    }

    #[DataProvider('malformed')]
    public function testMalformedEntryIsRefused(string $internal, string $migrations): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->endpoint($internal, $migrations);
    }

    public function testHostnameInInternalAddressesIsNotAnAllowance(): void
    {
        $this->endpoint('', 'appwrite')->validate('http://appwrite/v1');

        try {
            $this->endpoint('appwrite', '');
            $this->fail('Accepted a hostname in _APP_ALLOWED_INTERNAL_ADDRESSES');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('appwrite', $exception->getMessage());
        }
    }

    private function endpoint(string $internal = '', string $migrations = ''): Endpoint
    {
        $lookup = new FixedLookup([
            'example.com' => ['93.184.215.14'],
            'example.com.' => ['93.184.215.14'],
            'ipv6.example.com' => ['2606:4700:4700::1111'],
            'rebind.example.com' => ['93.184.215.14', '10.0.0.1'],
            'appwrite.example.com' => ['10.0.0.1'],
            'appwrite.test' => ['172.16.238.10'],
            'appwrite' => ['172.16.238.20'],
        ]);

        return new Endpoint($lookup, $internal, $migrations);
    }
}
