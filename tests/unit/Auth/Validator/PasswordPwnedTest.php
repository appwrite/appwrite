<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordPwned;
use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

final class PasswordPwnedTest extends TestCase
{
    private const LEAKED = 'Password123!';

    public function testLeakedPasswordIsRejected(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertFalse($validator->isValid(self::LEAKED));
    }

    public function testCleanPasswordIsAccepted(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertTrue($validator->isValid('never-leaked-' . \uniqid()));
    }

    public function testPaddedEntryWithZeroCountIsNotABreach(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 0]));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertTrue($validator->isValid(self::LEAKED));
    }

    public function testSuffixComparisonIgnoresCase(): void
    {
        $adapter = new RangeAdapter(body: \strtolower($this->range([self::LEAKED => 3])));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertFalse($validator->isValid(self::LEAKED));
    }

    public function testOnlyHashPrefixLeavesTheServer(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = new PasswordPwned('https://breaches.test/range/', new Client($adapter));

        $validator->isValid(self::LEAKED);

        $hash = \strtoupper(\sha1(self::LEAKED));
        $this->assertCount(1, $adapter->requests);
        $this->assertSame('https://breaches.test/range/' . \substr($hash, 0, 5), $adapter->requests[0]['url']);
        $this->assertSame('GET', $adapter->requests[0]['method']);
        $this->assertSame('true', $adapter->requests[0]['headers']['add-padding'] ?? null);
    }

    public function testUnreachableServiceSkipsTheCheck(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertTrue($validator->isValid(self::LEAKED));
    }

    public function testUnexpectedStatusSkipsTheCheck(): void
    {
        $adapter = new RangeAdapter(statusCode: 503, body: $this->range([self::LEAKED => 42]));
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertTrue($validator->isValid(self::LEAKED));
    }

    public function testBasePasswordRulesStillApply(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter));

        $this->assertFalse($validator->isValid('short'));
        $this->assertFalse($validator->isValid(\str_repeat('p', 257)));
        $this->assertFalse($validator->isValid(''));
        $this->assertCount(0, $adapter->requests);
    }

    public function testAllowEmptySkipsTheLookup(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = new PasswordPwned('https://breaches.test/range', new Client($adapter), allowEmpty: true);

        $this->assertTrue($validator->isValid(''));
        $this->assertCount(0, $adapter->requests);
    }

    public function testEmptyEndpointFallsBackToDefault(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = new PasswordPwned('', new Client($adapter));

        $validator->isValid(self::LEAKED);

        $this->assertStringStartsWith(PasswordPwned::ENDPOINT . '/', $adapter->requests[0]['url']);
    }

    /**
     * Builds a range response body the way the Have I Been Pwned API does:
     * one `SUFFIX:COUNT` line per leaked hash, CRLF separated, plus padding.
     *
     * @param array<string, int> $leaked password => breach count
     */
    private function range(array $leaked): string
    {
        $lines = [];
        foreach ($leaked as $password => $count) {
            $lines[] = \substr(\strtoupper(\sha1((string) $password)), 5) . ':' . $count;
        }
        $lines[] = \str_repeat('0', 35) . ':0';

        return \implode("\r\n", $lines);
    }
}

final class RangeAdapter implements Adapter
{
    /** @var array<int, array{url: string, method: string, headers: array<string, string>}> */
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
        $this->requests[] = ['url' => $url, 'method' => $method, 'headers' => $headers];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response($this->statusCode, $this->body, []);
    }
}
