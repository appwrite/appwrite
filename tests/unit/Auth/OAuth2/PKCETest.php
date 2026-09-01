<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Appwrite as AppwriteProvider;
use Appwrite\Auth\OAuth2\Cloudflare;
use Appwrite\Auth\OAuth2\Etsy;
use Appwrite\Auth\OAuth2\Kick;
use Appwrite\Auth\OAuth2\Resend;
use Appwrite\Auth\OAuth2\X;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PKCETest extends TestCase
{
    private const APP_ID = 'client-id';
    private const APP_SECRET = 'client-secret';
    private const CALLBACK = 'https://example.com/callback';

    /**
     * @return \Iterator<string, array{class-string<\Appwrite\Auth\OAuth2>}>
     */
    public static function providers(): \Iterator
    {
        yield 'etsy' => [Etsy::class];
        yield 'x' => [X::class];
        yield 'kick' => [Kick::class];
        yield 'appwrite' => [AppwriteProvider::class];
        yield 'resend' => [Resend::class];
        yield 'cloudflare' => [Cloudflare::class];
    }

    protected function setUp(): void
    {
        // The verifier is encrypted before it is placed in the state.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    /**
     * The adapter is rebuilt on the callback request, so the verifier sent at token
     * exchange must still hash to the challenge sent at authorization time. This is
     * the whole point of PKCE, and it is what breaks when the verifier is either
     * regenerated per instance or sent unhashed as the challenge.
     */
    #[DataProvider('providers')]
    public function testChallengeMatchesVerifierSentAtTokenExchange(string $provider): void
    {
        $login = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());

        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertNotSame('', $verifier, 'A code_verifier must be sent at token exchange.');
        $this->assertSame($this->s256($verifier), $login['code_challenge'] ?? null);
    }

    /**
     * RFC 7636 §4.2 — the S256 challenge is BASE64URL(SHA256(ASCII(verifier))).
     * Sending the bare verifier reduces the exchange to the `plain` method.
     */
    #[DataProvider('providers')]
    public function testChallengeIsNotTheRawVerifier(string $provider): void
    {
        $login = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());

        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertNotSame($verifier, $login['code_challenge'] ?? null);
    }

    #[DataProvider('providers')]
    public function testLoginUrlDeclaresS256(string $provider): void
    {
        $login = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());

        $this->assertSame('S256', $login['code_challenge_method'] ?? null);
        $this->assertNotEmpty($login['code_challenge'] ?? '');
    }

    /**
     * RFC 7636 §4.1 — 43 to 128 characters drawn from the unreserved set.
     */
    #[DataProvider('providers')]
    public function testVerifierMatchesRfc7636(string $provider): void
    {
        $login = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());

        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertGreaterThanOrEqual(43, \strlen($verifier));
        $this->assertLessThanOrEqual(128, \strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
    }

    /**
     * The state is echoed back through the provider and lands in the redirect URL,
     * browser history and referrer logs, so the verifier must not be readable there.
     */
    #[DataProvider('providers')]
    public function testVerifierIsNotExposedInLoginUrl(string $provider): void
    {
        $url = (new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL();

        $verifier = $this->exchangeAndCaptureVerifier($provider, $this->queryOf($url)['state'] ?? '');

        $this->assertStringNotContainsString($verifier, (string) $url);
        $this->assertStringNotContainsString(\rawurlencode($verifier), (string) $url);
    }

    #[DataProvider('providers')]
    public function testEachAuthorizationUsesAFreshVerifier(string $provider): void
    {
        $first = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());
        $second = $this->queryOf((new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->getLoginURL());

        $this->assertNotSame($first['code_challenge'] ?? null, $second['code_challenge'] ?? null);
        $this->assertNotSame(
            $this->exchangeAndCaptureVerifier($provider, $first['state'] ?? ''),
            $this->exchangeAndCaptureVerifier($provider, $second['state'] ?? ''),
        );
    }

    #[DataProvider('providers')]
    public function testCallerStateIsPreservedAndPkceEntryStripped(string $provider): void
    {
        $url = (new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK, [
            'success' => 'https://example.com/ok',
            'failure' => 'https://example.com/no',
        ]))->getLoginURL();

        $parsed = (new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))
            ->parseState($this->queryOf($url)['state'] ?? '');

        $this->assertIsArray($parsed);
        $this->assertSame('https://example.com/ok', $parsed['success'] ?? null);
        $this->assertSame('https://example.com/no', $parsed['failure'] ?? null);
        $this->assertArrayNotHasKey('_pkce', $parsed);
    }

    /**
     * The PKCE payload is rebuilt from the `state` query parameter, so its members
     * are attacker controlled and may not be strings at all. They must never reach
     * hex2bin()/base64_decode() unchecked, and a malformed payload must not adopt a
     * verifier of the attacker's choosing.
     */
    #[DataProvider('providers')]
    public function testMalformedPkceStateIsRejectedWithoutError(string $provider): void
    {
        $malformed = [
            ['data' => ['nested'], 'iv' => 'aa', 'tag' => 'bb'],
            ['data' => 'zz', 'iv' => 123, 'tag' => 'bb'],
            ['data' => 'zz', 'iv' => 'aa', 'tag' => ['nested']],
            ['data' => '', 'iv' => '', 'tag' => ''],
            ['data' => 'not-hex', 'iv' => 'not-hex', 'tag' => 'not-hex'],
        ];

        foreach ($malformed as $pkce) {
            $state = $this->encodeState($provider, ['success' => 'https://example.com', '_pkce' => $pkce]);

            $parsed = (new $provider(self::APP_ID, self::APP_SECRET, self::CALLBACK))->parseState($state);

            $this->assertIsArray($parsed);
            $this->assertArrayNotHasKey('_pkce', $parsed);
            $this->assertSame('https://example.com', $parsed['success'] ?? null);

            // A fresh RFC-compliant verifier is used rather than anything derived
            // from the malformed payload.
            $verifier = $this->exchangeAndCaptureVerifier($provider, $state);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
        }
    }

    /**
     * Drives the real callback path — parseState() then the token exchange — and
     * returns the `code_verifier` the provider actually put on the wire.
     *
     * @param class-string<OAuth2> $provider
     */
    private function exchangeAndCaptureVerifier(string $provider, string $state): string
    {
        $oauth = $this->getMockBuilder($provider)
            ->setConstructorArgs([self::APP_ID, self::APP_SECRET, self::CALLBACK])
            ->onlyMethods(['request'])
            ->getMock();

        $verifier = '';

        $oauth
            ->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                function (string $method, string $url = '', array $headers = [], string $payload = '') use (&$verifier): string {
                    \parse_str($payload, $params);

                    if (isset($params['code_verifier']) && \is_string($params['code_verifier'])) {
                        $verifier = $params['code_verifier'];
                    }

                    return \json_encode(['access_token' => 'access-token'], JSON_THROW_ON_ERROR);
                }
            );

        $oauth->parseState($state);
        $oauth->getAccessToken('authorization-code');

        return $verifier;
    }

    private function s256(string $verifier): string
    {
        return \rtrim(\strtr(\base64_encode(\hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param class-string<OAuth2> $provider
     * @param array<string, mixed> $state
     */
    private function encodeState(string $provider, array $state): string
    {
        $json = \json_encode($state, JSON_THROW_ON_ERROR);

        // X base64url-encodes its state payload; the others send raw JSON.
        return $provider === X::class
            ? \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=')
            : $json;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        \parse_str(\parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return $query;
    }
}
