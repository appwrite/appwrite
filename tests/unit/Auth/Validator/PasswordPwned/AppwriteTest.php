<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned\Appwrite;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\DSN\DSN;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppwriteTest extends TestCase
{
    private const PASSWORD = 'Password123!';
    private const SECRET = 'shared-with-the-service';
    private const DSN = 'appwrite://' . self::SECRET . '@pwned.test/v1/detection';

    public function testLeakedPasswordIsRejected(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":true}');

        $this->assertFalse($this->validator($fetch)->isValid(self::PASSWORD));
    }

    public function testCleanPasswordIsAccepted(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":false}');

        $this->assertTrue($this->validator($fetch)->isValid(self::PASSWORD));
    }

    public function testTheHashIsSentWithTheSharedSecretAsBearerToken(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":false}');

        $this->validator($fetch)->isValid(self::PASSWORD);

        $this->assertCount(1, $fetch->requests);
        $this->assertSame('POST', $fetch->requests[0]->getMethod());

        // The service takes the hash, so the password itself never travels
        $body = (string) $fetch->requests[0]->getBody();
        $this->assertSame(['hash' => \strtoupper(\sha1(self::PASSWORD))], \json_decode($body, true));
        $this->assertStringNotContainsString(self::PASSWORD, $body);

        $this->assertSame('Bearer ' . self::SECRET, $fetch->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $fetch->requests[0]->getHeaderLine('Content-Type'));
    }

    public function testTheDsnDescribesWhereAndHowToConnect(): void
    {
        $cases = [
            'appwrite://secret@pwned.test/v1/detection' => 'http://pwned.test/v1/detection',
            // The path the service exposes is assumed when the DSN leaves it out
            'appwrite://secret@pwned.test' => 'http://pwned.test/v1/detection',
            'appwrite://secret@pwned.test:8088' => 'http://pwned.test:8088/v1/detection',
            'appwrite://secret@pwned.test/custom/path' => 'http://pwned.test/custom/path',
            'appwrite://secret@pwned.test?tls=true' => 'https://pwned.test/v1/detection',
        ];

        foreach ($cases as $dsn => $expected) {
            $fetch = new DetectionFetch(body: '{"leaked":false}');

            (new Appwrite(new DSN($dsn), null, $fetch))->isValid(self::PASSWORD);

            $this->assertSame($expected, (string) $fetch->requests[0]->getUri(), $dsn);
        }
    }

    public function testUnreachableServiceIsReported(): void
    {
        $fetch = new DetectionFetch(failure: new \RuntimeException('connection refused'));

        try {
            $this->validator($fetch)->isValid(self::PASSWORD);
            $this->fail('Expected the unreachable service to surface');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
        }
    }

    /**
     * The service answers every failure with its own error object; none of them may pass as a clean password.
     */
    public function testServiceErrorsAreReported(): void
    {
        $errors = [
            401 => '{"type":"general_unauthorized","message":"Missing or invalid Bearer token in the Authorization header.","code":401,"version":"0.4.0"}',
            400 => '{"type":"general_argument_invalid","message":"Invalid `hash` param: Value must be a 40-character hexadecimal SHA-1 hash","code":400,"version":"0.4.0"}',
            503 => '{"type":"dataset_unavailable","message":"The password dataset could not be read.","code":503,"version":"0.4.0"}',
            500 => '',
        ];

        foreach ($errors as $statusCode => $body) {
            $fetch = new DetectionFetch(statusCode: $statusCode, body: $body);

            try {
                $this->validator($fetch)->isValid(self::PASSWORD);
                $this->fail('Expected a ' . $statusCode . ' to surface');
            } catch (Exception $e) {
                $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType(), (string) $statusCode);
            }
        }
    }

    /**
     * An answer the validator cannot read must never pass as a clean password.
     */
    public function testUnreadableAnswerIsReported(): void
    {
        foreach (['', 'not json', '{}', '{"leaked":"yes"}', '{"pwned":true}'] as $body) {
            $fetch = new DetectionFetch(body: $body);

            try {
                $this->validator($fetch)->isValid(self::PASSWORD);
                $this->fail('Expected an unreadable answer to surface: ' . $body);
            } catch (Exception $e) {
                $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
            }
        }
    }

    public function testAnswersAreCached(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":true}');
        $cache = new Cache(new Memory());

        $this->assertFalse((new Appwrite(new DSN(self::DSN), $cache, $fetch))->isValid(self::PASSWORD));
        $this->assertFalse((new Appwrite(new DSN(self::DSN), $cache, $fetch))->isValid(self::PASSWORD));

        $this->assertCount(1, $fetch->requests);
    }

    public function testEachPasswordGetsItsOwnAnswer(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":true}');
        $cache = new Cache(new Memory());
        $validator = new Appwrite(new DSN(self::DSN), $cache, $fetch);

        $validator->isValid(self::PASSWORD);
        $validator->isValid('a-completely-different-password');

        $this->assertCount(2, $fetch->requests);
    }

    public function testCacheIsScopedToEndpoint(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":true}');
        $cache = new Cache(new Memory());

        (new Appwrite(new DSN('appwrite://secret@one.test'), $cache, $fetch))->isValid(self::PASSWORD);
        (new Appwrite(new DSN('appwrite://secret@two.test'), $cache, $fetch))->isValid(self::PASSWORD);

        $this->assertCount(2, $fetch->requests);
    }

    public function testFailedLookupIsNotCached(): void
    {
        $fetch = new DetectionFetch(failure: new \RuntimeException('connection refused'));
        $validator = new Appwrite(new DSN(self::DSN), new Cache(new Memory()), $fetch);

        foreach ([1, 2] as $attempt) {
            try {
                $validator->isValid(self::PASSWORD);
            } catch (Exception) {
                // The lookup failed, which is the point; it must be retried rather than remembered
            }
        }

        $this->assertCount(2, $fetch->requests);
    }

    private function validator(DetectionFetch $fetch): Appwrite
    {
        return new Appwrite(new DSN(self::DSN), null, $fetch);
    }
}

final class DetectionFetch implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    public function __construct(
        private int $statusCode = 200,
        private string $body = '',
        private ?\Throwable $failure = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response($this->statusCode, body: new Stream($this->body));
    }
}
