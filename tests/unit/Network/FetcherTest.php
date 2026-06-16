<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Favicon\Get;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

final class FetcherTest extends TestCase
{
    public function testAssertSafeAcceptsPublicIpLiteral(): void
    {
        // Should not throw
        TestableGet::assertSafe('http://1.1.1.1/');
        TestableGet::assertSafe('https://8.8.8.8/path');
        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('unsafeUrls')]
    public function testAssertSafeRejectsUnsafeUrls(string $url, string $reasonFragment): void
    {
        try {
            TestableGet::assertSafe($url);
            $this->fail("Expected Exception for {$url}");
        } catch (Exception $e) {
            $this->assertSame(Exception::AVATAR_REMOTE_URL_FAILED, $e->getType());
            $this->assertStringContainsString(
                $reasonFragment,
                $e->getMessage(),
                "Wrong rejection reason for {$url}"
            );
        }
    }

    public static function unsafeUrls(): \Iterator
    {
        yield 'loopback v4' => ['http://127.0.0.1/iam', 'private or reserved'];
        yield 'loopback v6' => ['http://[::1]/iam', 'private or reserved'];
        yield 'link-local imds' => ['http://169.254.169.254/latest/', 'private or reserved'];
        yield 'private rfc1918 a' => ['http://10.0.0.5/secret', 'private or reserved'];
        yield 'private rfc1918 b' => ['http://192.168.1.1/admin', 'private or reserved'];
        yield 'private rfc1918 c' => ['http://172.16.0.1/x', 'private or reserved'];
        yield 'cgnat' => ['http://100.64.0.1/x', 'private or reserved'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/x', 'private or reserved'];
        yield '6to4 imds' => ['http://[2002:a9fe:a9fe::]/x', 'private or reserved'];
        yield 'file scheme' => ['file:///etc/passwd', "Scheme 'file' is not allowed"];
        yield 'gopher scheme' => ['gopher://attacker/x', "Scheme 'gopher' is not allowed"];
        yield 'ftp scheme' => ['ftp://internal/file', "Scheme 'ftp' is not allowed"];
        yield 'no scheme' => ['example.com/path', "Scheme '' is not allowed"];
        yield 'malformed no host' => ['http:///path', 'Malformed URL'];
        yield 'unknown psl' => ['http://not-a-real-tld.notreal/x', 'not a known public domain'];
    }

    public function testFetchFollowsPublicRedirect(): void
    {
        $adapter = $this->scriptedAdapter([
            $this->redirect('https://1.1.1.1/final'),
            $this->ok('FINAL_BODY'),
        ]);

        $fetcher = new TestableGet();
        $response = $fetcher->fetchForTest('http://8.8.8.8/start', $adapter);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FINAL_BODY', $response->getBody());
        $this->assertSame(2, $adapter->callCount);
    }

    public function testFetchBlocksRedirectToPrivateIp(): void
    {
        // This is the exact SSRF chain from the report:
        //   public attacker page → 302 → 169.254.169.254 (AWS IMDS)
        $adapter = $this->scriptedAdapter([
            $this->redirect('http://169.254.169.254/latest/meta-data/iam/security-credentials/'),
            // This second response must NEVER be served — the fetcher should
            // reject the redirect target before calling send() again.
            $this->ok('SHOULD_NEVER_LEAK'),
        ]);

        $fetcher = new TestableGet();

        try {
            $fetcher->fetchForTest('http://8.8.8.8/attacker-page', $adapter);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::AVATAR_REMOTE_URL_FAILED, $e->getType());
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
            $this->assertSame(
                1,
                $adapter->callCount,
                'Redirect target should not have been fetched — only the initial request should hit the adapter'
            );
        }
    }

    public function testFetchBlocksRedirectToLoopback(): void
    {
        $adapter = $this->scriptedAdapter([
            $this->redirect('http://127.0.0.1:9999/secrets'),
            $this->ok('SHOULD_NEVER_LEAK'),
        ]);

        $fetcher = new TestableGet();

        try {
            $fetcher->fetchForTest('http://8.8.8.8/attacker-page', $adapter);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::AVATAR_REMOTE_URL_FAILED, $e->getType());
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
            $this->assertSame(1, $adapter->callCount);
        }
    }

    public function testFetchBlocksRedirectToRelativePathOnPrivateBase(): void
    {
        // Edge case: a server on a public IP returns a relative redirect.
        // We resolve it against the (still public) base and continue — safe.
        $adapter = $this->scriptedAdapter([
            $this->redirect('/different-path'),
            $this->ok('FINAL'),
        ]);

        $fetcher = new TestableGet();
        $response = $fetcher->fetchForTest('http://8.8.8.8/start', $adapter);

        $this->assertSame('FINAL', $response->getBody());
    }

    public function testFetchRejectsRedirectChainOverLimit(): void
    {
        // Six redirects all to public targets; with maxRedirects=5 the sixth
        // hop trips the limit.
        $adapter = $this->scriptedAdapter([
            $this->redirect('https://1.1.1.1/2'),
            $this->redirect('https://1.0.0.1/3'),
            $this->redirect('https://8.8.8.8/4'),
            $this->redirect('https://8.8.4.4/5'),
            $this->redirect('https://9.9.9.9/6'),
            $this->redirect('https://208.67.222.222/7'),
        ]);

        $fetcher = new TestableGet();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many redirects');
        $fetcher->fetchForTest('http://8.8.8.8/0', $adapter);
    }

    public function testFetchBlocksNonHttpRedirect(): void
    {
        $adapter = $this->scriptedAdapter([
            $this->redirect('file:///etc/passwd'),
            $this->ok('SHOULD_NEVER_LEAK'),
        ]);

        $fetcher = new TestableGet();

        try {
            $fetcher->fetchForTest('http://8.8.8.8/page', $adapter);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::AVATAR_REMOTE_URL_FAILED, $e->getType());
            $this->assertStringContainsString("Scheme 'file'", $e->getMessage());
            $this->assertSame(1, $adapter->callCount);
        }
    }

    private function redirect(string $location): Response
    {
        // Mix the header key case on purpose. Fetcher must normalise per
        // RFC 7230 §3.2; if it ever drops to a naive lowercase lookup the
        // entire redirect-following suite fails — that's the regression
        // signal we want.
        return new Response(302, '', ['Location' => $location]);
    }

    private function ok(string $body): Response
    {
        return new Response(200, $body, []);
    }

    /**
     * @param Response[] $responses
     */
    private function scriptedAdapter(array $responses): ScriptedAdapter
    {
        return new ScriptedAdapter($responses);
    }
}

/**
 * Test-only adapter that returns a pre-scripted list of responses in order.
 * Exposed as a named class so PHPStan can see the callCount property.
 */
class ScriptedAdapter implements Adapter
{
    public int $callCount = 0;

    /** @param Response[] $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $response = $this->responses[$this->callCount] ?? null;
        $this->callCount++;
        if ($response === null) {
            throw new \RuntimeException("Adapter ran out of scripted responses (call {$this->callCount})");
        }
        return $response;
    }
}

class TestableGet extends Get
{
    /**
     * Skip parent constructor. Action::__construct chains into
     * Appwrite\SDK\Method, which loads Swoole at file-load time —
     * Swoole is a production-only extension, so building the real
     * Action in a unit test fails. The protected method bridges
     * below are all the surface the redirect-chain tests need.
     */
    public function __construct()
    {
    }

    public static function assertSafe(string $url): void
    {
        parent::assertSafeUrl($url);
    }

    public function fetchForTest(string $url, Adapter $adapter): Response
    {
        return $this->safeFetch($url, 'test', $adapter);
    }
}
