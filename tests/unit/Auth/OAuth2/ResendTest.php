<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Resend;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResendTest extends TestCase
{
    private const APP_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const APP_SECRET = 'client-secret';
    private const CALLBACK = 'https://example.com/v1/account/sessions/oauth2/callback/resend/console';
    private const TOKEN_URL = 'https://api.resend.com/oauth/token';

    protected function setUp(): void
    {
        // Building the login URL encrypts the PKCE verifier into the state.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    /**
     * Resend exposes no userinfo endpoint and no id_token, so the account id
     * is only available as the `sub` claim of the JWT access token.
     */
    public function testUserIdComesFromAccessTokenClaims(): void
    {
        $resend = $this->createResend();

        $this->assertSame('user_01HQ8', $resend->getUserID($this->createJWT(['sub' => 'user_01HQ8'])));
    }

    /**
     * JWT segments are base64url without padding. Decoding them as padded
     * base64 would drop the claims and silently produce an empty user id,
     * which reads as a brand new account on every login.
     */
    public function testUserIdIsReadFromUnpaddedSegments(): void
    {
        foreach (['a', 'ab', 'abc', 'abcd', 'abcde'] as $sub) {
            $token = $this->createJWT(['sub' => $sub]);
            $payload = \explode('.', $token)[1];

            $this->assertStringNotContainsString('=', $payload);
            $this->assertSame($sub, $this->createResend()->getUserID($token));
        }
    }

    /**
     * JWT segments are base64url, whose alphabet swaps "+/" for "-_". A claim
     * holding non-ASCII text (a team name, say) is enough to produce those
     * characters, and decoding such a segment as standard base64 fails
     * outright rather than degrading.
     */
    public function testUserIdIsReadWhenSegmentUsesBase64UrlAlphabet(): void
    {
        // Resend signs with a JSON serializer that emits raw UTF-8 rather than
        // \u escapes, so the encoded segment carries the high bits verbatim.
        $payload = $this->base64UrlEncode(
            \json_encode(['sub' => 'u', 'name' => "Caf\u{FFFD}"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $token = \implode('.', [$this->base64UrlEncode('{"typ":"at+jwt"}'), $payload, 'signature']);

        $this->assertMatchesRegularExpression('/[-_]/', $payload);
        $this->assertFalse(\base64_decode($payload, true), 'Expected the segment to be invalid standard base64.');
        $this->assertSame('u', $this->createResend()->getUserID($token));
    }

    /**
     * The access token is attacker-reachable through the callback, so a
     * malformed one must not raise; it simply yields no identity.
     */
    public function testMalformedAccessTokenYieldsNoUserId(): void
    {
        $tokens = [
            '',
            'not-a-jwt',
            'header-only.',
            'header.%%%not-base64%%%.signature',
            'header.' . $this->base64UrlEncode('not json at all') . '.signature',
            $this->createJWT(['aud' => 'resend']),
        ];

        foreach ($tokens as $token) {
            $this->assertSame('', $this->createResend()->getUserID($token), \sprintf('Token "%s"', $token));
        }
    }

    /**
     * A JSON payload that is not an object would otherwise reach array access
     * on a scalar.
     */
    public function testNonObjectPayloadYieldsNoUserId(): void
    {
        $token = 'header.' . $this->base64UrlEncode('"just-a-string"') . '.signature';

        $this->assertSame('', $this->createResend()->getUserID($token));
    }

    public function testNumericSubjectIsReturnedAsString(): void
    {
        $this->assertSame('12345', $this->createResend()->getUserID($this->createJWT(['sub' => 12345])));
    }

    /**
     * Resend grants access to a team's email API and never discloses the
     * account's email or name, so nothing may be invented here.
     */
    public function testProfileFieldsAreNotExposed(): void
    {
        $resend = $this->createResend();
        $token = $this->createJWT(['sub' => 'user_01HQ8', 'email' => 'someone@example.com']);

        $this->assertSame('', $resend->getUserEmail($token));
        $this->assertSame('', $resend->getUserName($token));
        $this->assertFalse($resend->isEmailVerified($token));
    }

    /**
     * Omitting `scope` makes Resend grant `full_access` on top of sending, so
     * the adapter must always ask for the narrow scope explicitly.
     */
    public function testLoginUrlRequestsLeastPrivilegeScope(): void
    {
        $url = $this->createResend()->getLoginURL();
        \parse_str(\parse_url($url, PHP_URL_QUERY) ?: '', $query);

        $this->assertStringStartsWith('https://api.resend.com/oauth/authorize?', $url);
        $this->assertSame('emails:send', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::APP_ID, $query['client_id']);
        $this->assertSame(self::CALLBACK, $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
    }

    /**
     * Basic auth already identifies the client, and the documented exchange
     * does not repeat `client_id` in the body. The PKCE verifier is required.
     */
    public function testTokenExchangeAuthenticatesWithBasicAuthAndVerifier(): void
    {
        $resend = $this->createResendWithTokenResponse(
            \json_encode(['access_token' => 'access-token', 'refresh_token' => 'refresh-token'], JSON_THROW_ON_ERROR),
            function (array $body): void {
                $this->assertSame([
                    'grant_type' => 'authorization_code',
                    'code' => 'authorization-code',
                    'redirect_uri' => self::CALLBACK,
                    'code_verifier' => $body['code_verifier'] ?? '',
                ], $body);
                $this->assertNotEmpty($body['code_verifier']);
            }
        );

        $this->assertSame('access-token', $resend->getAccessToken('authorization-code'));
    }

    /**
     * Refresh tokens rotate on every refresh, but a response that omits one
     * must not wipe the stored grant.
     */
    public function testRefreshKeepsPreviousTokenWhenNoneReturned(): void
    {
        $resend = $this->createResendWithTokenResponse(
            \json_encode(['access_token' => 'new-access-token'], JSON_THROW_ON_ERROR),
            function (array $body): void {
                $this->assertSame([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => 'old-refresh-token',
                ], $body);
            }
        );

        $tokens = $resend->refreshTokens('old-refresh-token');

        $this->assertSame('new-access-token', $tokens['access_token']);
        $this->assertSame('old-refresh-token', $tokens['refresh_token']);
    }

    public function testRefreshStoresRotatedToken(): void
    {
        $resend = $this->createResendWithTokenResponse(
            \json_encode([
                'access_token' => 'new-access-token',
                'refresh_token' => 'rotated-refresh-token',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertSame('rotated-refresh-token', $resend->refreshTokens('old-refresh-token')['refresh_token']);
    }

    private function createResend(): Resend
    {
        return new Resend(self::APP_ID, self::APP_SECRET, self::CALLBACK, ['success' => 'https://example.com'], []);
    }

    /**
     * @param callable(array<string, string>): void|null $assertBody
     */
    private function createResendWithTokenResponse(string $response, ?callable $assertBody = null): Resend&MockObject
    {
        $resend = $this->getMockBuilder(Resend::class)
            ->setConstructorArgs([self::APP_ID, self::APP_SECRET, self::CALLBACK, [], []])
            ->onlyMethods(['request'])
            ->getMock();

        $resend
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                self::TOKEN_URL,
                $this->callback(function (array $headers): bool {
                    $this->assertContains('Content-Type: application/x-www-form-urlencoded', $headers);
                    $this->assertContains(
                        'Authorization: Basic ' . \base64_encode(self::APP_ID . ':' . self::APP_SECRET),
                        $headers,
                    );

                    return true;
                }),
                $this->callback(function (mixed $payload) use ($assertBody): bool {
                    if (!\is_string($payload)) {
                        return false;
                    }

                    \parse_str($payload, $body);
                    $this->assertArrayNotHasKey('client_id', $body);
                    $this->assertArrayNotHasKey('client_secret', $body);

                    if ($assertBody !== null) {
                        $assertBody($body);
                    }

                    return true;
                }),
            )
            ->willReturn($response);

        return $resend;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function createJWT(array $claims): string
    {
        return \implode('.', [
            $this->base64UrlEncode(\json_encode(['alg' => 'ES256', 'typ' => 'at+jwt'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(\json_encode($claims, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode('signature'),
        ]);
    }

    private function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }
}
