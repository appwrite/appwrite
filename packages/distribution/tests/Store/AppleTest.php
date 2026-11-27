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
use Utopia\Distribution\Issue;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Tests\Failure;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppleTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string IDENTIFIER = 'io.example.app';

    private const string APP_ID = '6450000001';

    private const int NOW = 1_795_000_000;

    private const string ENDPOINT = 'https://store.test/apple';

    private static string $privateKey = '';

    private static string $publicKey = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        self::$privateKey = $private;
        self::$publicKey = $details['key'];
    }

    public function testNameIsApple(): void
    {
        $this->assertSame('apple', $this->store(new Transport())->getName());
    }

    public function testAccountListsOneAppAndReturnsIssuer(): void
    {
        $transport = new Transport($this->reply('apps'));

        $account = $this->store($transport)->account();

        $this->assertSame(self::ISSUER_ID, $account->id);
        $this->assertNull($account->team);
        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/apps?limit=1', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
    }

    public function testDefaultEndpointIsAppStoreConnect(): void
    {
        $transport = new Transport($this->reply('apps'));

        new Apple($this->credential(), $transport)->account();

        $this->assertSame('https://api.appstoreconnect.apple.com/v1/apps?limit=1', (string) $this->only($transport)->getUri());
    }

    public function testTokenHeaderClaimsAndSignature(): void
    {
        $transport = new Transport($this->reply('apps'));

        $this->store($transport)->account();

        [$header, $claims, $signature] = explode('.', $this->token($this->only($transport)));
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => self::KEY_ID], $this->json($header));
        $this->assertSame([
            'iss' => self::ISSUER_ID,
            'iat' => self::NOW - 60,
            'exp' => self::NOW + 1140,
            'aud' => 'appstoreconnect-v1',
        ], $this->json($claims));
        $raw = $this->decode($signature);
        $this->assertSame(64, \strlen($raw));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->der($raw), self::$publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testTokenIsReusedUntilRefreshInterval(): void
    {
        $transport = new Transport($this->reply('apps'), $this->reply('apps'), $this->reply('apps'));
        $store = $this->store($transport);

        $store->account();
        $store->advance(Apple::TOKEN_REFRESH - 1);
        $store->account();
        $store->advance(1);
        $store->account();

        [$first, $second, $third] = array_map($this->token(...), $transport->requests());
        $this->assertSame($first, $second);
        $this->assertNotSame($second, $third);
        $claims = $this->claims($third);
        $this->assertSame(self::NOW + Apple::TOKEN_REFRESH - Apple::TOKEN_SKEW, $claims['iat']);
        $this->assertSame(self::NOW + Apple::TOKEN_REFRESH - Apple::TOKEN_SKEW + Apple::TOKEN_LIFETIME, $claims['exp']);
    }

    public function testUnauthorizedIsRetriedOnceWithFreshToken(): void
    {
        $transport = new Transport($this->reply('error-unauthorized', 401), $this->reply('apps'));
        $store = $this->store($transport);

        $account = $store->account();

        $this->assertSame(self::ISSUER_ID, $account->id);
        $requests = $transport->requests();
        $this->assertCount(2, $requests);
        $this->assertNotSame($this->token($requests[0]), $this->token($requests[1]));
        $this->assertSame((string) $requests[0]->getUri(), (string) $requests[1]->getUri());
    }

    public function testRepeatedUnauthorizedRejectsCredential(): void
    {
        $transport = new Transport($this->reply('error-unauthorized', 401), $this->reply('error-unauthorized', 401));

        try {
            $this->store($transport)->account();
            $this->fail('account should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertTrue($unauthorized->isCredential());
            $this->assertSame(['NOT_AUTHORIZED'], $this->codes($unauthorized));
        }

        $this->assertCount(2, $transport->requests());
    }

    public function testAppChoosesExactBundleIdAmongPrefixMatches(): void
    {
        $transport = new Transport($this->reply('apps'));

        $app = $this->store($transport)->app(self::IDENTIFIER);

        $this->assertSame(self::APP_ID, $app->id);
        $this->assertSame(self::IDENTIFIER, $app->identifier);
        $this->assertSame('Example', $app->name);
        $request = $this->only($transport);
        $this->assertSame(self::ENDPOINT . '/v1/apps', $this->path($request));
        $this->assertSame([
            'filter' => ['bundleId' => self::IDENTIFIER],
            'fields' => ['apps' => 'name,bundleId,sku,primaryLocale'],
            'limit' => '200',
        ], $this->query($request));
    }

    public function testAppWithOnlyPrefixMatchesThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->store(new Transport($this->reply('apps')))->app('io.example');
    }

    public function testAppWithEmptyDataThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->store(new Transport($this->reply('apps-empty')))->app('io.example.missing');
    }

    public function testLatestVersionCodeIsNumericMaximum(): void
    {
        $transport = new Transport($this->reply('apps'), $this->reply('builds'));

        $this->assertSame(10, $this->store($transport)->latestVersionCode(self::IDENTIFIER));

        $requests = $transport->requests();
        $this->assertCount(2, $requests);
        $this->assertSame(self::ENDPOINT . '/v1/builds', $this->path($requests[1]));
        $this->assertSame([
            'filter' => ['app' => self::APP_ID],
            'sort' => '-uploadedDate',
            'limit' => '200',
            'fields' => ['builds' => 'version'],
        ], $this->query($requests[1]));
    }

    public function testLatestVersionCodeWithoutBuildsIsZero(): void
    {
        $transport = new Transport($this->reply('apps'), $this->reply('builds-empty'));

        $this->assertSame(0, $this->store($transport)->latestVersionCode(self::IDENTIFIER));
    }

    public function testLatestVersionCodeOfUnknownAppThrowsNotFound(): void
    {
        $transport = new Transport($this->reply('apps-empty'));

        try {
            $this->store($transport)->latestVersionCode('io.example.missing');
            $this->fail('latestVersionCode should throw');
        } catch (NotFound) {
            $this->assertCount(1, $transport->requests());
        }
    }

    /**
     * @return iterable<string, array{int, ?string, class-string<Exception>, list<string>}>
     */
    public static function failures(): iterable
    {
        yield '400 parameter error' => [400, 'error-parameter', Invalid::class, ['PARAMETER_ERROR.INVALID']];
        yield '403 agreements' => [403, 'error-agreements', Unauthorized::class, ['FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED']];
        yield '403 forbidden' => [403, 'error-forbidden', Unauthorized::class, ['FORBIDDEN_ERROR']];
        yield '404 not found' => [404, 'error-not-found', NotFound::class, ['NOT_FOUND']];
        yield '404 path error' => [404, 'error-path', Invalid::class, ['PATH_ERROR']];
        yield '405 method not allowed' => [405, 'error-method', Invalid::class, ['METHOD_NOT_ALLOWED']];
        yield '409 state error' => [409, 'error-state', Conflict::class, ['STATE_ERROR', 'STATE_ERROR.SCREENSHOT_REQUIRED.APP_IPHONE_67', 'ENTITY_ERROR.ATTRIBUTE.REQUIRED']];
        yield '409 entity error' => [409, 'error-entity', Invalid::class, ['ENTITY_ERROR.ATTRIBUTE.INVALID']];
        yield '409 without errors' => [409, null, Invalid::class, []];
        yield '410 gone' => [410, 'error-not-found', NotFound::class, ['NOT_FOUND']];
        yield '418 unmapped client error' => [418, null, Invalid::class, []];
        yield '422 unprocessable' => [422, 'error-unprocessable', Invalid::class, ['ENTITY_UNPROCESSABLE']];
        yield '429 rate limit' => [429, 'error-rate', RateLimited::class, ['RATE_LIMIT_EXCEEDED']];
        yield '500 server error' => [500, 'error-server', Unavailable::class, ['UNEXPECTED_ERROR']];
        yield '502 bad gateway' => [502, null, Unavailable::class, []];
        yield '503 unavailable' => [503, 'error-server', Unavailable::class, ['UNEXPECTED_ERROR']];
        yield '504 gateway timeout' => [504, null, Unavailable::class, []];
        yield '599 other server error' => [599, null, Unavailable::class, []];
    }

    /**
     * @param class-string<Exception> $expected
     * @param list<string> $codes
     */
    #[DataProvider('failures')]
    public function testFailureMapsToException(int $status, ?string $fixture, string $expected, array $codes): void
    {
        $reply = $fixture === null ? $this->page($status, '<html><body>Gateway</body></html>') : $this->reply($fixture, $status);

        try {
            $this->store(new Transport($reply))->app(self::IDENTIFIER);
            $this->fail("{$status} should throw");
        } catch (Exception $exception) {
            $this->assertInstanceOf($expected, $exception);
            $this->assertSame($codes, $this->codes($exception));
            $this->assertStringContainsString("App Store Connect returned {$status}", $exception->getMessage());
            $this->assertSame((string) $reply->getBody(), $exception->getBody());

            if ($exception instanceof Unauthorized) {
                $this->assertFalse($exception->isCredential());
            }
        }
    }

    public function testAgreementsIssueNeedsManualAction(): void
    {
        $issue = $this->issue(403, 'error-agreements');

        $this->assertTrue($issue->manual);
        $this->assertSame('This request requires an in-effect agreement that has not been signed or has expired.', $issue->message);
    }

    public function testForbiddenIssueIsNotManual(): void
    {
        $this->assertFalse($this->issue(403, 'error-forbidden')->manual);
    }

    public function testIssueCarriesErrorIdAsReference(): void
    {
        $this->assertSame('0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0003', $this->issue(403, 'error-forbidden')->reference);
    }

    public function testRepeatedUnauthorizedKeepsRawBody(): void
    {
        $reply = $this->reply('error-unauthorized', 401);

        try {
            $this->store(new Transport($this->reply('error-unauthorized', 401), $reply))->account();
            $this->fail('account should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertSame((string) $reply->getBody(), $unauthorized->getBody());
            $this->assertSame('0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0001', $unauthorized->getIssues()[0]->reference);
        }
    }

    public function testEntityIssueCarriesPointerAsField(): void
    {
        $issue = $this->issue(409, 'error-entity');

        $this->assertSame('/data/attributes/versionString', $issue->field);
        $this->assertSame("The version string '1.0.0.0.1' is not valid.", $issue->message);
    }

    public function testAssociatedErrorsAreFlattened(): void
    {
        $issues = $this->issues(409, 'error-state');

        $this->assertCount(3, $issues);
        $this->assertSame([
            '0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0008',
            '0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0009',
            '0c5a5f53-6b3e-4b0e-9a63-6f2a2f1d0010',
        ], array_map(static fn (Issue $issue): ?string => $issue->reference, $issues));
        $this->assertSame('App screenshot missing (APP_IPHONE_67).', $issues[1]->message);
        $this->assertNull($issues[1]->field);
        $this->assertSame('/data/attributes/usesNonExemptEncryption', $issues[2]->field);
    }

    public function testIssueWithoutDetailFallsBackToTitle(): void
    {
        $issue = $this->issue(409, 'error-relationship');

        $this->assertSame('ENTITY_ERROR.RELATIONSHIP.INVALID', $issue->code);
        $this->assertSame('The provided entity includes a relationship with an invalid value', $issue->message);
        $this->assertSame('/data/relationships/build', $issue->field);
    }

    public function testSpentRateLimitStopsTheNextCall(): void
    {
        $transport = new Transport($this->reply('apps', remaining: 0), $this->reply('apps'));
        $store = $this->store($transport);

        $store->account();

        try {
            $store->account();
            $this->fail('account should throw');
        } catch (RateLimited) {
            $this->assertCount(1, $transport->requests());
        }
    }

    public function testSpentRateLimitStopsTheUnauthorizedRetry(): void
    {
        $transport = new Transport($this->reply('error-unauthorized', 401, remaining: 0), $this->reply('apps'));

        try {
            $this->store($transport)->account();
            $this->fail('account should throw');
        } catch (RateLimited) {
            $this->assertCount(1, $transport->requests());
        }
    }

    public function testSpentRateLimitRecoversAfterAnHour(): void
    {
        $transport = new Transport($this->reply('apps', remaining: 0), $this->reply('apps'));
        $store = $this->store($transport);

        $store->account();
        $store->advance(3600);
        $store->account();

        $this->assertCount(2, $transport->requests());
    }

    public function testRemainingAllowanceKeepsSending(): void
    {
        $transport = new Transport($this->reply('apps', remaining: 1), $this->reply('apps', remaining: 0));
        $store = $this->store($transport);

        $store->account();
        $store->account();

        $this->assertCount(2, $transport->requests());
    }

    public function testMalformedRateLimitHeaderIsIgnored(): void
    {
        $transport = new Transport(
            Transport::fixture('Apple/apps', 200, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:none;']),
            $this->reply('apps'),
        );
        $store = $this->store($transport);

        $store->account();
        $store->account();

        $this->assertCount(2, $transport->requests());
    }

    public function testTransportFailureThrowsUnavailable(): void
    {
        $failure = new Failure('connection reset');

        try {
            $this->store(new Transport($failure))->account();
            $this->fail('account should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame($failure, $unavailable->getPrevious());
        }
    }

    public function testSuccessWithoutJsonThrowsUnavailable(): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->page(200, 'ok')))->account();
    }

    public function testSuccessWithoutDataThrowsUnavailable(): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->page(200, '{"links":{}}')))->app(self::IDENTIFIER);
    }

    public function testPromoteIsUnsupported(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->promote(self::IDENTIFIER, '42');
            $this->fail('promote should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('promote is not supported by apple', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen($this->credential(), $transport, self::NOW, self::ENDPOINT . '/');
    }

    private function credential(): Credential\Apple
    {
        return new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey);
    }

    private function reply(string $fixture, int $status = 200, int $remaining = 3499): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => "user-hour-lim:3500;user-hour-rem:{$remaining};"]);
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
    }

    private function only(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertCount(1, $requests);

        return $requests[0];
    }

    private function token(RequestInterface $request): string
    {
        $authorization = $request->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Bearer ', $authorization);

        return substr($authorization, \strlen('Bearer '));
    }

    /**
     * @return array<mixed>
     */
    private function claims(string $token): array
    {
        return $this->json(explode('.', $token)[1] ?? '');
    }

    private function path(RequestInterface $request): string
    {
        return (string) $request->getUri()->withQuery('');
    }

    /**
     * @return array<mixed>
     */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * @return list<Issue>
     */
    private function issues(int $status, string $fixture): array
    {
        try {
            $this->store(new Transport($this->reply($fixture, $status)))->app(self::IDENTIFIER);
        } catch (Exception $exception) {
            return $exception->getIssues();
        }

        $this->fail("{$status} should throw");
    }

    private function issue(int $status, string $fixture): Issue
    {
        $issues = $this->issues($status, $fixture);
        $this->assertCount(1, $issues);

        return $issues[0];
    }

    /**
     * @return list<string>
     */
    private function codes(Exception $exception): array
    {
        return array_map(static fn (Issue $issue): string => $issue->code, $exception->getIssues());
    }

    /**
     * @return array<mixed>
     */
    private function json(string $segment): array
    {
        $decoded = json_decode($this->decode($segment), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function decode(string $segment): string
    {
        $bytes = base64_decode(strtr($segment, '-_', '+/'), true);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function der(string $raw): string
    {
        $body = $this->integer(substr($raw, 0, 32)) . $this->integer(substr($raw, 32));

        return "\x30" . pack('C', \strlen($body)) . $body;
    }

    private function integer(string $part): string
    {
        $part = ltrim($part, "\x00");
        if ($part === '' || (\ord($part[0]) & 0x80) !== 0) {
            $part = "\x00" . $part;
        }

        return "\x02" . pack('C', \strlen($part)) . $part;
    }
}
