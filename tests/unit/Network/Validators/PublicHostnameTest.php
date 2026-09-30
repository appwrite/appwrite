<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\PublicHostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class PublicHostnameTest extends TestCase
{
    public function testRejectsEmptyAndNonString(): void
    {
        $validator = new PublicHostname();

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(123));
        $this->assertFalse($validator->isValid([]));
    }

    #[DataProvider('privateIpv4Addresses')]
    public function testRejectsPrivateIpv4Literals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertFalse(
            $validator->isValid($ip),
            "Expected {$ip} to be rejected as private/reserved"
        );
        $this->assertFalse(PublicHostname::isPublicIp($ip));
    }

    public static function privateIpv4Addresses(): \Iterator
    {
        yield 'unspecified' => ['0.0.0.0'];
        yield 'private 10/8' => ['10.0.0.1'];
        yield 'private 172.16/12' => ['172.16.5.10'];
        yield 'private 192.168/16' => ['192.168.1.1'];
        yield 'cgnat' => ['100.64.0.1'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'link-local imds' => ['169.254.169.254'];
        yield 'gcp metadata' => ['169.254.169.254'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved 240/4' => ['240.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'test-net-1' => ['192.0.2.1'];
        yield 'test-net-2' => ['198.51.100.1'];
        yield 'test-net-3' => ['203.0.113.1'];
        yield 'benchmark' => ['198.18.0.1'];
    }

    #[DataProvider('privateIpv6Addresses')]
    public function testRejectsPrivateIpv6Literals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertFalse(
            $validator->isValid($ip),
            "Expected {$ip} to be rejected as private/reserved"
        );
        $this->assertFalse(PublicHostname::isPublicIp($ip));
    }

    public static function privateIpv6Addresses(): \Iterator
    {
        yield 'loopback' => ['::1'];
        yield 'unspecified' => ['::'];
        yield 'link-local' => ['fe80::1'];
        yield 'unique-local' => ['fc00::1'];
        yield 'unique-local fd' => ['fd12:3456:789a::1'];
        yield 'multicast' => ['ff02::1'];
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'ipv4-mapped private' => ['::ffff:10.0.0.1'];
        yield 'ipv4-mapped imds' => ['::ffff:169.254.169.254'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield '6to4 imds' => ['2002:a9fe:a9fe::'];
        yield 'teredo' => ['2001:0:1::1'];
        yield 'documentation' => ['2001:db8::1'];
    }

    #[DataProvider('publicIpAddresses')]
    public function testAcceptsPublicIpLiterals(string $ip): void
    {
        $validator = new PublicHostname();

        $this->assertTrue(
            $validator->isValid($ip),
            "Expected {$ip} to be accepted as public"
        );
        $this->assertTrue(PublicHostname::isPublicIp($ip));
    }

    public static function publicIpAddresses(): \Iterator
    {
        yield 'google dns' => ['8.8.8.8'];
        yield 'cloudflare' => ['1.1.1.1'];
        yield 'opendns' => ['208.67.222.222'];
        yield 'public ipv6' => ['2606:4700:4700::1111'];
    }

    public function testRejectsMalformedInput(): void
    {
        $validator = new PublicHostname();

        $this->assertFalse($validator->isValid('not a hostname at all'));
        $this->assertFalse($validator->isValid('http://example.com'));
        $this->assertFalse($validator->isValid('999.999.999.999'));
    }

    public function testRejectsNonResolvingHostname(): void
    {
        $validator = new PublicHostname();

        // RFC 2606 reserves this for testing — guaranteed not to resolve.
        $this->assertFalse($validator->isValid('a-hostname-that-does-not-exist.invalid'));
    }

    public function testReasonIsPopulatedOnFailure(): void
    {
        $validator = new PublicHostname();
        $validator->isValid('127.0.0.1');

        $this->assertStringContainsString('127.0.0.1', $validator->getDescription());
        $this->assertStringContainsString('private', $validator->getDescription());
    }

    public function testReasonResetsBetweenCalls(): void
    {
        $validator = new PublicHostname();

        $validator->isValid('');
        $this->assertSame('Hostname is empty.', $validator->getDescription());

        $validator->isValid('127.0.0.1');
        $this->assertStringContainsString('127.0.0.1', $validator->getDescription());
    }

    public function testIpInCidrEdgesViaPublicIp(): void
    {
        // Boundary checks: first and last address of common ranges.
        $this->assertFalse(PublicHostname::isPublicIp('10.0.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('10.255.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('11.0.0.0'));
        $this->assertTrue(PublicHostname::isPublicIp('9.255.255.255'));

        $this->assertFalse(PublicHostname::isPublicIp('169.254.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('169.254.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('169.253.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('169.255.0.0'));

        $this->assertFalse(PublicHostname::isPublicIp('100.64.0.0'));
        $this->assertFalse(PublicHostname::isPublicIp('100.127.255.255'));
        $this->assertTrue(PublicHostname::isPublicIp('100.128.0.0'));
        $this->assertTrue(PublicHostname::isPublicIp('100.63.255.255'));
    }

    public function testResolveSendsRequestToCheckedAddresses(): void
    {
        $listener = $this->listen('127.0.0.1');
        $port = $this->port($listener);
        $validator = new LoopbackHostname();

        $this->assertTrue($validator->isValid('pinned.invalid'));
        $request = $this->capture($listener, "http://pinned.invalid:{$port}/", $validator->getResolve($port));

        $this->assertStringContainsString("Host: pinned.invalid:{$port}\r\n", $request);
    }

    public function testResolveSendsRequestToCheckedIpv6Address(): void
    {
        $listener = $this->listen('[::1]');
        $port = $this->port($listener);
        $validator = new LoopbackHostname();

        $this->assertTrue($validator->isValid('pinned6.invalid'));
        $request = $this->capture($listener, "http://pinned6.invalid:{$port}/", $validator->getResolve($port));

        $this->assertStringContainsString("Host: pinned6.invalid:{$port}\r\n", $request);
    }

    public function testResolveKeepsTrailingDot(): void
    {
        $listener = $this->listen('127.0.0.1');
        $port = $this->port($listener);
        $validator = new LoopbackHostname();

        $this->assertTrue($validator->isValid('Pinned.INVALID.'));
        $request = $this->capture($listener, "http://pinned.invalid.:{$port}/", $validator->getResolve($port));

        $this->assertStringContainsString("Host: pinned.invalid.:{$port}\r\n", $request);
    }

    public function testResolveIsEmptyForIpLiterals(): void
    {
        $validator = new PublicHostname();

        $this->assertTrue($validator->isValid('8.8.8.8'));
        $this->assertSame([], $validator->getResolve(80));
    }

    public function testResolveResetsAfterRejection(): void
    {
        $validator = new LoopbackHostname();

        $this->assertTrue($validator->isValid('pinned.invalid'));
        $this->assertFalse($validator->isValid('missing.invalid'));
        $this->assertSame([], $validator->getResolve(80));
    }

    #[RunInSeparateProcess]
    public function testResolvesHostnameInsideCoroutine(): void
    {
        $validator = new PublicHostname();
        $valid = null;

        $this->inHookedCoroutine(function () use ($validator, &$valid): void {
            $valid = $validator->isValid('localhost');
        });

        $this->assertFalse($valid);
        $this->assertStringContainsString('Hostname localhost resolves to private or reserved address', $validator->getDescription());
    }

    #[RunInSeparateProcess]
    public function testResolvingInsideCoroutineRetainsNoMemoryPerLookup(): void
    {
        $validator = new PublicHostname();
        $lookups = 200;
        $growth = null;

        $this->inHookedCoroutine(function () use ($validator, $lookups, &$growth): void {
            $validator->isValid('localhost');
            \gc_collect_cycles();
            $before = \memory_get_usage();

            for ($i = 0; $i < $lookups; $i++) {
                $validator->isValid('localhost');
            }

            \gc_collect_cycles();
            $growth = \memory_get_usage() - $before;
        });

        // The hooked dns_get_record() retained ~140 KiB per lookup.
        $this->assertLessThan(4 * 1024, $growth / $lookups);
    }

    private function inHookedCoroutine(callable $callback): void
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        Coroutine\run($callback);
    }

    /**
     * Listens on a free port and never answers.
     *
     * @return resource
     */
    private function listen(string $address)
    {
        $listener = @\stream_socket_server("tcp://{$address}:0");

        if ($listener === false) {
            $this->markTestSkipped("Cannot listen on {$address}.");
        }

        return $listener;
    }

    /**
     * @param resource $listener
     */
    private function port($listener): int
    {
        $name = (string) \stream_socket_get_name($listener, false);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }

    /**
     * Sends a GET through the curl adapter with the given CURLOPT_RESOLVE
     * entries and returns the raw request that reached the listener.
     *
     * @param resource $listener
     * @param array<string> $resolve
     */
    private function capture($listener, string $url, array $resolve): string
    {
        $client = (new Client(new CurlAdapter(options: [CURLOPT_RESOLVE => $resolve])))->withTimeout(0.25);

        try {
            $client->sendRequest((new RequestFactory())->createRequest(Method::GET, $url));
        } catch (TimeoutException) {
            // The listener never answers
        }

        $connection = @\stream_socket_accept($listener, 1);
        $this->assertNotFalse($connection, "No request reached the listener for {$url}.");
        \stream_set_timeout($connection, 1);

        return (string) \fread($connection, 8192);
    }
}

/**
 * Resolves test hostnames to loopback and accepts every address, so requests
 * land on a local listener instead of leaving the machine.
 */
class LoopbackHostname extends PublicHostname
{
    public static function resolve(string $hostname): array
    {
        return match ($hostname) {
            'pinned.invalid', 'pinned.invalid.' => ['127.0.0.1', '::1'],
            'pinned6.invalid' => ['::1'],
            default => [],
        };
    }

    public static function isPublicIp(string $ip): bool
    {
        return true;
    }
}
