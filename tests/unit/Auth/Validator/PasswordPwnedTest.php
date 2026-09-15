<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

final class PasswordPwnedTest extends TestCase
{
    private const LEAKED = 'Password123!';
    private const ENDPOINT = 'https://breaches.test/range';

    public function testDisabledByDefault(): void
    {
        $validator = new PasswordPwned();

        $this->assertFalse($validator->isEnabled());
        $this->assertFalse($validator->isForceReset());
    }

    public function testPolicyFlags(): void
    {
        $validator = new PasswordPwned(['enabled' => true, 'forceReset' => true]);

        $this->assertTrue($validator->isEnabled());
        $this->assertTrue($validator->isForceReset());
    }

    public function testDisabledPolicyNeverChecks(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = new PasswordPwned(['enabled' => false], null, self::ENDPOINT, new Client($adapter));

        $this->assertNull($validator->check(self::LEAKED));
        $this->assertTrue($validator->isValid(self::LEAKED));
        $this->assertCount(0, $adapter->requests);
    }

    public function testCheckReportsBreachState(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = $this->validator([], $adapter);

        $this->assertTrue($validator->check(self::LEAKED));
        $this->assertFalse($validator->check('never-leaked-' . \uniqid()));
    }

    public function testCheckNotRequiredReturnsUnknownOnOutage(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $validator = $this->validator(['failClosed' => true], $adapter);

        $this->assertNull($validator->check(self::LEAKED, required: false));
    }

    public function testLeakedPasswordIsRejected(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = $this->validator([], $adapter);

        $this->assertFalse($validator->isValid(self::LEAKED));
    }

    public function testCleanPasswordIsAccepted(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $validator = $this->validator([], $adapter);

        $this->assertTrue($validator->isValid('never-leaked-' . \uniqid()));
    }

    public function testPaddedEntryWithZeroCountIsNotABreach(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 0]));
        $validator = $this->validator([], $adapter);

        $this->assertTrue($validator->isValid(self::LEAKED));
    }

    public function testSuffixComparisonIgnoresCase(): void
    {
        $adapter = new RangeAdapter(body: \strtolower($this->range([self::LEAKED => 3])));
        $validator = $this->validator([], $adapter);

        $this->assertFalse($validator->isValid(self::LEAKED));
    }

    public function testThresholdToleratesRareBreaches(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 9]));

        $this->assertTrue($this->validator(['threshold' => 10], $adapter)->isValid(self::LEAKED));
        $this->assertFalse($this->validator(['threshold' => 9], $adapter)->isValid(self::LEAKED));
    }

    public function testOnlyHashPrefixLeavesTheServer(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = $this->validator([], $adapter);

        $validator->isValid(self::LEAKED);

        $hash = \strtoupper(\sha1(self::LEAKED));
        $this->assertCount(1, $adapter->requests);
        $this->assertSame(self::ENDPOINT . '/' . \substr($hash, 0, 5), $adapter->requests[0]['url']);
        $this->assertSame('GET', $adapter->requests[0]['method']);
        $this->assertSame('true', $adapter->requests[0]['headers']['add-padding'] ?? null);
    }

    public function testRangeIsCachedPerPrefix(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $cache = new Cache(new Memory());

        $first = new PasswordPwned(['enabled' => true], $cache, self::ENDPOINT, new Client($adapter));
        $second = new PasswordPwned(['enabled' => true], $cache, self::ENDPOINT, new Client($adapter));

        $this->assertFalse($first->isValid(self::LEAKED));
        $this->assertFalse($second->isValid(self::LEAKED));
        $this->assertCount(1, $adapter->requests);
    }

    public function testCacheIsScopedToEndpoint(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 42]));
        $cache = new Cache(new Memory());

        (new PasswordPwned(['enabled' => true], $cache, 'https://one.test/range', new Client($adapter)))->isValid(self::LEAKED);
        (new PasswordPwned(['enabled' => true], $cache, 'https://two.test/range', new Client($adapter)))->isValid(self::LEAKED);

        $this->assertCount(2, $adapter->requests);
    }

    public function testUnreachableServiceFailsClosedByDefault(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $validator = $this->validator([], $adapter);

        try {
            $validator->isValid(self::LEAKED);
            $this->fail('Expected the check to fail closed');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
        }
    }

    public function testUnexpectedStatusFailsClosedByDefault(): void
    {
        $adapter = new RangeAdapter(statusCode: 503, body: '');
        $validator = $this->validator([], $adapter);

        $this->expectException(Exception::class);

        $validator->isValid(self::LEAKED);
    }

    public function testUnreachableServiceSkipsTheCheckWhenFailingOpen(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $validator = $this->validator(['failClosed' => false], $adapter);

        $this->assertTrue($validator->isValid(self::LEAKED));
    }

    public function testFailedLookupIsNotCached(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $cache = new Cache(new Memory());
        $validator = new PasswordPwned(['enabled' => true, 'failClosed' => false], $cache, self::ENDPOINT, new Client($adapter));

        $validator->isValid(self::LEAKED);
        $validator->isValid(self::LEAKED);

        $this->assertCount(2, $adapter->requests);
    }

    public function testBasePasswordRulesStillApply(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = $this->validator([], $adapter);

        $this->assertFalse($validator->isValid('short'));
        $this->assertFalse($validator->isValid(\str_repeat('p', 257)));
        $this->assertFalse($validator->isValid(''));
        $this->assertCount(0, $adapter->requests);
    }

    public function testAllowEmptySkipsTheLookup(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = new PasswordPwned(['enabled' => true], null, self::ENDPOINT, new Client($adapter), allowEmpty: true);

        $this->assertTrue($validator->isValid(''));
        $this->assertCount(0, $adapter->requests);
    }

    public function testEndpointPrecedence(): void
    {
        $adapter = new RangeAdapter(body: '');
        $prefix = \substr(\strtoupper(\sha1(self::LEAKED)), 0, 5);

        (new PasswordPwned(['enabled' => true, 'endpoint' => 'https://policy.test/range/'], null, 'https://server.test/range', new Client($adapter)))->isValid(self::LEAKED);
        (new PasswordPwned(['enabled' => true, 'endpoint' => ''], null, 'https://server.test/range', new Client($adapter)))->isValid(self::LEAKED);
        (new PasswordPwned(['enabled' => true], null, '', new Client($adapter)))->isValid(self::LEAKED);

        $this->assertSame('https://policy.test/range/' . $prefix, $adapter->requests[0]['url']);
        $this->assertSame('https://server.test/range/' . $prefix, $adapter->requests[1]['url']);
        $this->assertSame(PasswordPwned::ENDPOINT . '/' . $prefix, $adapter->requests[2]['url']);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function validator(array $policy, RangeAdapter $adapter): PasswordPwned
    {
        return new PasswordPwned(\array_merge(['enabled' => true], $policy), null, self::ENDPOINT, new Client($adapter));
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
