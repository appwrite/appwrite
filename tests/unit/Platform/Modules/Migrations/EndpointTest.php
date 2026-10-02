<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Migrations\Endpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;

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
        foreach ([$this->endpoint()->resolve(...), $this->endpoint()->validate(...)] as $check) {
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
        $endpoint = $this->endpoint(new IPRange('172.16.238.0/24'));

        $this->assertSame(['appwrite.test:80:172.16.238.10'], $endpoint->resolve('http://appwrite.test/v1'));
        $this->assertSame([], $endpoint->resolve('http://172.16.238.10/v1'));

        $this->expectException(Exception::class);
        $endpoint->resolve('http://127.0.0.1/v1');
    }

    public function testHostAllowedByNameIsNotPinned(): void
    {
        $endpoint = $this->endpoint(hostnames: ['Appwrite.']);

        $this->assertSame([], $endpoint->resolve('http://appwrite/v1'));
        $this->assertSame([], $endpoint->resolve('http://APPWRITE./v1'));
        $endpoint->validate('http://appwrite/v1');
    }

    /**
     * @param list<string> $hostnames
     */
    private function endpoint(?IPRange $range = null, array $hostnames = []): Endpoint
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

        $destinations = $range === null ? new PublicInternet() : new PublicInternet($range);

        return new Endpoint(new PublicHostname($destinations, $lookup), $hostnames);
    }
}
