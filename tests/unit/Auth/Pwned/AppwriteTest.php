<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Pwned;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Pwned\Appwrite;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\DSN\DSN;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

final class AppwriteTest extends TestCase
{
    private const PASSWORD = 'Password123!';
    private const SECRET = 'shared-with-the-service';
    private const DSN = 'appwrite://' . self::SECRET . '@pwned.test/v1/detection';

    public function testLeakedPasswordIsReported(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":true}');

        $this->assertTrue($this->adapter($fetch)->isPwned(self::PASSWORD));
    }

    public function testCleanPasswordIsNotReported(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":false}');

        $this->assertFalse($this->adapter($fetch)->isPwned(self::PASSWORD));
    }

    public function testThePasswordTravelsInAJwtTheServiceCanOpen(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":false}');

        $this->adapter($fetch)->isPwned(self::PASSWORD);

        $this->assertCount(1, $fetch->requests);
        $this->assertSame('POST', $fetch->requests[0]['method']);
        $this->assertSame('http://pwned.test/v1/detection', $fetch->requests[0]['url']);

        $sent = \json_decode($fetch->requests[0]['body'], true);
        $this->assertIsArray($sent);
        $this->assertArrayHasKey('password', $sent);

        // The service decodes with the shared secret, so it must round-trip
        $decoded = (new JWT(self::SECRET, 'HS256', 900, 10))->decode($sent['password']);
        $this->assertSame(self::PASSWORD, $decoded['password']);
    }

    public function testATokenSignedWithAnotherSecretIsNotAccepted(): void
    {
        $fetch = new DetectionFetch(body: '{"leaked":false}');

        $this->adapter($fetch)->isPwned(self::PASSWORD);

        $sent = \json_decode($fetch->requests[0]['body'], true);

        $this->expectException(\Throwable::class);

        (new JWT('a-different-secret', 'HS256', 900, 10))->decode($sent['password']);
    }

    public function testUnreachableServiceIsReported(): void
    {
        $fetch = new DetectionFetch(failure: new \RuntimeException('connection refused'));

        try {
            $this->adapter($fetch)->isPwned(self::PASSWORD);
            $this->fail('Expected the unreachable service to surface');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
        }
    }

    public function testRejectedTokenIsReported(): void
    {
        // The service answers 400 when it cannot decode the token
        $fetch = new DetectionFetch(statusCode: 400, body: '{"message":"Failed to verify JWT."}');

        $this->expectException(Exception::class);

        $this->adapter($fetch)->isPwned(self::PASSWORD);
    }

    public function testDetectorFailureIsReported(): void
    {
        $fetch = new DetectionFetch(statusCode: 500, body: '');

        $this->expectException(Exception::class);

        $this->adapter($fetch)->isPwned(self::PASSWORD);
    }

    /**
     * A body the adapter cannot read must never be taken as a clean password.
     */
    public function testUnreadableAnswerIsReported(): void
    {
        foreach (['', 'not json', '{}', '{"leaked":"yes"}', '{"pwned":true}'] as $body) {
            $fetch = new DetectionFetch(body: $body);

            try {
                $this->adapter($fetch)->isPwned(self::PASSWORD);
                $this->fail('Expected an unreadable answer to surface: ' . $body);
            } catch (Exception $e) {
                $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
            }
        }
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

            (new Appwrite(new DSN($dsn), new Client($fetch)))->isPwned(self::PASSWORD);

            $this->assertSame($expected, $fetch->requests[0]['url'], $dsn);
        }
    }

    private function adapter(DetectionFetch $fetch): Appwrite
    {
        return new Appwrite(new DSN(self::DSN), new Client($fetch));
    }
}

final class DetectionFetch implements Adapter
{
    /** @var array<int, array{url: string, method: string, body: mixed}> */
    public array $requests = [];

    public function __construct(
        private int $statusCode = 200,
        private string $body = '',
        private ?\Throwable $failure = null,
    ) {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $this->requests[] = ['url' => $url, 'method' => $method, 'body' => $body];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response($this->statusCode, $this->body, []);
    }
}
