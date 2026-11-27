<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Store\Google;
use Utopia\Distribution\Tests\Failure;
use Utopia\Distribution\Tests\Store\Google\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class GoogleTest extends TestCase
{
    private const string EMAIL = 'publisher@example-project.iam.gserviceaccount.com';

    private const string PACKAGE = 'io.example.app';

    private const string ENDPOINT = 'https://play.test/v1/mock/distribution/google';

    private const string TOKEN = self::ENDPOINT . '/token';

    private const string EDITS = self::ENDPOINT . '/androidpublisher/v3/applications/io.example.app/edits';

    private const string EDIT = '06155296137469138127';

    private const string ACCESS_TOKEN = 'ya29.c.fixture-access-token';

    private const string REFRESHED_TOKEN = 'ya29.c.fixture-refreshed-token';

    private const int NOW = 1_800_000_000;

    /**
     * @var array{string, string}|null
     */
    private static ?array $keys = null;

    public function testName(): void
    {
        $this->assertSame('google', $this->store(new Transport())->getName());
    }

    public function testAccountExchangesSignedAssertion(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));

        $account = $this->store($transport)->account();

        $this->assertSame(self::EMAIL, $account->id);
        $this->assertNull($account->team);

        $requests = $transport->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertSame(self::TOKEN, (string) $requests[0]->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $requests[0]->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=', (string) $requests[0]->getBody());

        [$header, $claims, $signature] = $this->assertion($requests[0]);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'RS256'], $this->json($header));
        $this->assertSame([
            'iss' => self::EMAIL,
            'scope' => Google::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => self::NOW,
            'exp' => self::NOW + 3600,
        ], $this->json($claims));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->decode($signature), $this->keys()[1], OPENSSL_ALGO_SHA256));
    }

    public function testDefaultEndpointsAreGoogles(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/edit'), new Response(204));

        new Google($this->credential(), $transport)->app(self::PACKAGE);

        $requests = $transport->requests();
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $requests[0]->getUri());
        $this->assertSame('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/io.example.app/edits', (string) $requests[1]->getUri());
    }

    public function testTokenIsCachedAcrossCalls(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            new Response(204),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks'),
            new Response(204),
        );
        $store = $this->store($transport);

        $store->account();
        $store->app(self::PACKAGE);
        $store->latestVersionCode(self::PACKAGE);

        $requests = $transport->requests();
        $this->assertCount(6, $requests);
        $this->assertSame([self::TOKEN], $this->uris(\array_slice($requests, 0, 1)));
        foreach (\array_slice($requests, 1) as $request) {
            $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        }
    }

    public function testTokenIsRefreshedFiveMinutesBeforeExpiry(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/token-refreshed'));
        $store = $this->store($transport);

        $store->account();
        $store->advance(3600 - 300 - 1);
        $store->account();

        $this->assertCount(1, $transport->requests());

        $store->advance(1);
        $store->account();

        $requests = $transport->requests();
        $this->assertCount(2, $requests);
        $this->assertSame(self::TOKEN, (string) $requests[1]->getUri());
        [, $claims] = $this->assertion($requests[1]);
        $this->assertSame(self::NOW + 3300, $this->json($claims)['iat']);
    }

    public function testAppInsertsThenDeletesEdit(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/edit'), new Response(204));

        $app = $this->store($transport)->app(self::PACKAGE);

        $this->assertSame(self::PACKAGE, $app->id);
        $this->assertSame(self::PACKAGE, $app->identifier);
        $this->assertNull($app->name);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'DELETE'], $this->methods($requests));
        $this->assertSame([self::TOKEN, self::EDITS, self::EDITS . '/' . self::EDIT], $this->uris($requests));
        $this->assertSame('{}', (string) $requests[1]->getBody());
        $this->assertSame('application/json', $requests[1]->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $requests[2]->getHeaderLine('Authorization'));
    }

    public function testPackageNameIsUrlEncoded(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/edit'), new Response(204));

        $this->store($transport)->app('io.example app/../edits');

        $this->assertSame(
            self::ENDPOINT . '/androidpublisher/v3/applications/io.example%20app%2F..%2Fedits/edits',
            (string) $transport->requests()[1]->getUri(),
        );
    }

    public function testEmptyPackageNameThrowsInvalidBeforeSending(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));

        try {
            $this->store($transport)->app('');
            $this->fail('app should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('Google Play package name must not be empty', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    public function testLatestVersionCodeIsNumericMaximumAcrossTracks(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks'),
            new Response(204),
        );

        $this->assertSame(10, $this->store($transport)->latestVersionCode(self::PACKAGE));

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'DELETE'], $this->methods($requests));
        $this->assertSame(
            [self::TOKEN, self::EDITS, self::EDITS . '/' . self::EDIT . '/tracks', self::EDITS . '/' . self::EDIT],
            $this->uris($requests),
        );
    }

    public function testLatestVersionCodeIsZeroWithoutReleases(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks-empty'),
            new Response(204),
        );

        $this->assertSame(0, $this->store($transport)->latestVersionCode(self::PACKAGE));
    }

    public function testLatestVersionCodeDeletesEditWhenTracksFail(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/error-unavailable', 503),
            new Response(204),
        );

        try {
            $this->store($transport)->latestVersionCode(self::PACKAGE);
            $this->fail('latestVersionCode should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame('Google Play returned 503: The service is currently unavailable.', $unavailable->getMessage());
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'DELETE'], $this->methods($requests));
        $this->assertSame(self::EDITS . '/' . self::EDIT, (string) $requests[3]->getUri());
    }

    public function testLatestVersionCodeReportsTracksFailureWhenDeleteAlsoFails(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/error-not-found', 404),
            Transport::fixture('Google/error-unavailable', 503),
        );

        try {
            $this->store($transport)->latestVersionCode(self::PACKAGE);
            $this->fail('latestVersionCode should throw');
        } catch (Exception $exception) {
            $this->assertInstanceOf(NotFound::class, $exception);
            $this->assertSame('Google Play returned 404: Package not found: io.example.app.', $exception->getMessage());
        }

        $this->assertSame(['POST', 'POST', 'GET', 'DELETE'], $this->methods($transport->requests()));
    }

    public function testLatestVersionCodeReportsDeleteFailureAfterTracksSucceed(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/error-unavailable', 503),
        );

        $this->expectException(Unavailable::class);

        $this->store($transport)->latestVersionCode(self::PACKAGE);
    }

    public function testAppSucceedsWhenEditDeleteFails(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/error-internal', 500),
        );

        $app = $this->store($transport)->app(self::PACKAGE);

        $this->assertSame(self::PACKAGE, $app->id);
        $this->assertSame(['POST', 'POST', 'DELETE'], $this->methods($transport->requests()));
    }

    public function testAppSucceedsWhenEditDeleteTransportFails(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/edit'), new Failure('connection reset'));

        $this->assertSame(self::PACKAGE, $this->store($transport)->app(self::PACKAGE)->identifier);
    }

    public function testUnauthenticatedResponseRefreshesTokenAndRetriesOnce(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/error-unauthenticated', 401),
            Transport::fixture('Google/token-refreshed'),
            Transport::fixture('Google/edit'),
            new Response(204),
        );

        $this->store($transport)->app(self::PACKAGE);

        $requests = $transport->requests();
        $this->assertSame([self::TOKEN, self::EDITS, self::TOKEN, self::EDITS, self::EDITS . '/' . self::EDIT], $this->uris($requests));
        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::REFRESHED_TOKEN, $requests[3]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . self::REFRESHED_TOKEN, $requests[4]->getHeaderLine('Authorization'));
    }

    public function testSecondUnauthenticatedResponseThrowsUnauthorized(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/error-unauthenticated', 401),
            Transport::fixture('Google/token-refreshed'),
            Transport::fixture('Google/error-unauthenticated', 401),
        );

        try {
            $this->store($transport)->app(self::PACKAGE);
            $this->fail('app should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertFalse($unauthorized->isCredential());
            $this->assertSame('authError', $unauthorized->getIssues()[0]->code);
        }

        $this->assertCount(4, $transport->requests());
    }

    /**
     * @return iterable<string, array{bool, ResponseInterface, class-string<Exception>, bool, string|null}>
     */
    public static function failures(): iterable
    {
        yield 'token signature rejected' => [true, Transport::fixture('Google/token-signature', 400), Unauthorized::class, true, 'invalid_grant'];
        yield 'token account not found' => [true, Transport::fixture('Google/token-account', 400), Unauthorized::class, true, 'invalid_grant'];
        yield 'token outside timeframe' => [true, Transport::fixture('Google/token-timeframe', 400), Unauthorized::class, false, 'invalid_grant'];
        yield 'token endpoint down' => [true, Transport::fixture('Google/error-unavailable', 503), Unavailable::class, false, 'UNAVAILABLE'];
        yield '403 permission denied' => [false, Transport::fixture('Google/error-permission', 403), Unauthorized::class, false, 'forbidden'];
        yield '403 version code conflict' => [false, Transport::fixture('Google/error-version-conflict', 403), Unauthorized::class, false, 'apkUpgradeVersionConflict'];
        yield '404 application not found' => [false, Transport::fixture('Google/error-not-found', 404), NotFound::class, false, 'applicationNotFound'];
        yield '409 aborted' => [false, Transport::fixture('Google/error-aborted', 409), Conflict::class, false, 'editAlreadyCommitted'];
        yield '400 failed precondition' => [false, Transport::fixture('Google/error-in-review', 400), Conflict::class, false, 'CHANGES_ALREADY_IN_REVIEW'];
        yield '429 quota' => [false, Transport::fixture('Google/error-quota', 429), RateLimited::class, false, 'rateLimitExceeded'];
        yield '400 bad request' => [false, Transport::fixture('Google/error-invalid', 400), Invalid::class, false, 'badRequest'];
        yield '418 unmapped' => [false, new Response(418), Invalid::class, false, null];
        yield '500 internal' => [false, Transport::fixture('Google/error-internal', 500), Unavailable::class, false, 'backendError'];
        yield '502 gateway' => [false, self::html(502), Unavailable::class, false, null];
        yield '503 unavailable' => [false, Transport::fixture('Google/error-unavailable', 503), Unavailable::class, false, 'UNAVAILABLE'];
        yield '504 timeout' => [false, new Response(504), Unavailable::class, false, null];
    }

    /**
     * @param class-string<Exception> $expected
     */
    #[DataProvider('failures')]
    public function testFailureMapping(bool $token, ResponseInterface $response, string $expected, bool $credential, ?string $issue): void
    {
        $transport = $token ? new Transport($response) : new Transport(Transport::fixture('Google/token'), $response);

        try {
            $this->store($transport)->app(self::PACKAGE);
            $this->fail('app should throw');
        } catch (Exception $exception) {
            $this->assertInstanceOf($expected, $exception);
            $this->assertSame((string) $response->getBody(), $exception->getBody());

            if ($exception instanceof Unauthorized) {
                $this->assertSame($credential, $exception->isCredential());
            }

            $issues = $exception->getIssues();
            if ($issue === null) {
                $this->assertSame([], $issues);
            } else {
                $this->assertCount(1, $issues);
                $this->assertSame($issue, $issues[0]->code);
                $this->assertNotSame('', $issues[0]->message);
            }
        }

        $this->assertCount($token ? 1 : 2, $transport->requests());
    }

    public function testTokenTransportFailureThrowsUnavailable(): void
    {
        $failure = new Failure('connection refused');

        try {
            $this->store(new Transport($failure))->account();
            $this->fail('account should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame($failure, $unavailable->getPrevious());
        }
    }

    public function testApiTransportFailureThrowsUnavailable(): void
    {
        $failure = new Failure('timed out');

        try {
            $this->store(new Transport(Transport::fixture('Google/token'), $failure))->app(self::PACKAGE);
            $this->fail('app should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame($failure, $unavailable->getPrevious());
        }
    }

    public function testTokenResponseWithoutAccessTokenThrowsUnavailable(): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport(new Response(200, body: new Stream\Factory()->createStream('{}'))))->account();
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen($this->credential(), $transport, self::NOW, self::ENDPOINT, self::TOKEN);
    }

    private function credential(): Credential\Google
    {
        return new Credential\Google(self::EMAIL, $this->keys()[0]);
    }

    /**
     * @return array{string, string}
     */
    private function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || !openssl_pkey_export($key, $private) || !\is_string($private)) {
            throw new \LogicException('RSA key generation failed');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !\is_string($details['key'] ?? null)) {
            throw new \LogicException('RSA public key could not be read');
        }

        return self::$keys = [$private, $details['key']];
    }

    private static function html(int $status): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream('<html><body>Bad Gateway</body></html>'))
            ->withHeader('Content-Type', 'text/html');
    }

    /**
     * @return array{string, string, string}
     */
    private function assertion(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $form);
        $assertion = $form['assertion'] ?? null;
        $this->assertIsString($assertion);

        $segments = explode('.', $assertion);
        $this->assertCount(3, $segments);

        return [$segments[0], $segments[1], $segments[2]];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(string $segment): array
    {
        $decoded = json_decode($this->decode($segment), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function decode(string $segment): string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function methods(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod(), $requests);
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function uris(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => (string) $request->getUri(), $requests);
    }
}
