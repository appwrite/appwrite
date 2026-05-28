<?php

namespace Tests\Unit\Network;

use Appwrite\Network\Fetcher;
use Appwrite\Network\UnsafeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

class FetcherTest extends TestCase
{
    public function testAssertSafeAcceptsPublicIpLiteral(): void
    {
        // Should not throw
        Fetcher::assertSafe('http://1.1.1.1/');
        Fetcher::assertSafe('https://8.8.8.8/path');
        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('unsafeUrls')]
    public function testAssertSafeRejectsUnsafeUrls(string $url, string $reasonFragment): void
    {
        try {
            Fetcher::assertSafe($url);
            $this->fail("Expected UnsafeUrlException for {$url}");
        } catch (UnsafeUrlException $e) {
            $this->assertStringContainsString(
                $reasonFragment,
                $e->getMessage(),
                "Wrong rejection reason for {$url}"
            );
        }
    }

    public static function unsafeUrls(): array
    {
        return [
            'loopback v4'           => ['http://127.0.0.1/iam', 'private or reserved'],
            'loopback v6'           => ['http://[::1]/iam', 'private or reserved'],
            'link-local imds'       => ['http://169.254.169.254/latest/', 'private or reserved'],
            'private rfc1918 a'     => ['http://10.0.0.5/secret', 'private or reserved'],
            'private rfc1918 b'     => ['http://192.168.1.1/admin', 'private or reserved'],
            'private rfc1918 c'     => ['http://172.16.0.1/x', 'private or reserved'],
            'cgnat'                 => ['http://100.64.0.1/x', 'private or reserved'],
            'ipv4-mapped loopback'  => ['http://[::ffff:127.0.0.1]/x', 'private or reserved'],
            '6to4 imds'             => ['http://[2002:a9fe:a9fe::]/x', 'private or reserved'],
            'file scheme'           => ['file:///etc/passwd', "Scheme 'file' is not allowed"],
            'gopher scheme'         => ['gopher://attacker/x', "Scheme 'gopher' is not allowed"],
            'ftp scheme'            => ['ftp://internal/file', "Scheme 'ftp' is not allowed"],
            'no scheme'             => ['example.com/path', "Scheme '' is not allowed"],
            'malformed no host'     => ['http:///path', 'Malformed URL'],
            'unknown psl'           => ['http://not-a-real-tld.notreal/x', 'not a known public domain'],
        ];
    }

    public function testFetchFollowsPublicRedirect(): void
    {
        $adapter = $this->scriptedAdapter([
            $this->redirect('https://1.1.1.1/final'),
            $this->ok('FINAL_BODY'),
        ]);

        $fetcher = new Fetcher(userAgent: 'test', adapter: $adapter);
        $response = $fetcher->fetch('http://8.8.8.8/start');

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

        $fetcher = new Fetcher(userAgent: 'test', adapter: $adapter);

        try {
            $fetcher->fetch('http://8.8.8.8/attacker-page');
            $this->fail('Expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
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

        $fetcher = new Fetcher(userAgent: 'test', adapter: $adapter);

        try {
            $fetcher->fetch('http://8.8.8.8/attacker-page');
            $this->fail('Expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
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

        $fetcher = new Fetcher(userAgent: 'test', adapter: $adapter);
        $response = $fetcher->fetch('http://8.8.8.8/start');

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

        $fetcher = new Fetcher(userAgent: 'test', maxRedirects: 5, adapter: $adapter);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many redirects');
        $fetcher->fetch('http://8.8.8.8/0');
    }

    public function testFetchBlocksNonHttpRedirect(): void
    {
        $adapter = $this->scriptedAdapter([
            $this->redirect('file:///etc/passwd'),
            $this->ok('SHOULD_NEVER_LEAK'),
        ]);

        $fetcher = new Fetcher(userAgent: 'test', adapter: $adapter);

        try {
            $fetcher->fetch('http://8.8.8.8/page');
            $this->fail('Expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
            $this->assertStringContainsString("Scheme 'file'", $e->getMessage());
            $this->assertSame(1, $adapter->callCount);
        }
    }

    private function redirect(string $location): Response
    {
        return new Response(302, '', ['location' => $location]);
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
