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

    public function testEnabledByDefaultWithoutSessionChecks(): void
    {
        $validator = new PasswordPwned();

        $this->assertTrue($validator->isEnabled());
        $this->assertFalse($validator->checksSessions());
        $this->assertFalse($validator->blocksUsers());
    }

    public function testPolicyFlags(): void
    {
        $validator = new PasswordPwned(['enabled' => true, 'sessions' => true, 'users' => true]);

        $this->assertTrue($validator->isEnabled());
        $this->assertTrue($validator->checksSessions());
        $this->assertTrue($validator->blocksUsers());
    }

    public function testSessionChecksNeedAnEnabledPolicy(): void
    {
        $validator = new PasswordPwned(['enabled' => false, 'sessions' => true, 'users' => true]);

        $this->assertFalse($validator->checksSessions());
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

    public function testAnySingleBreachIsEnough(): void
    {
        $adapter = new RangeAdapter(body: $this->range([self::LEAKED => 1]));

        $this->assertFalse($this->validator([], $adapter)->isValid(self::LEAKED));
    }

    public function testOnlyTheHashPrefixLeavesTheServer(): void
    {
        $adapter = new RangeAdapter(body: '');
        $validator = $this->validator([], $adapter);

        $validator->isValid(self::LEAKED);

        $this->assertCount(1, $adapter->requests);

        $hash = \strtoupper(\sha1(self::LEAKED));
        $url = $adapter->requests[0];

        // Neither the password nor anything that identifies it may reach the service
        $this->assertStringNotContainsStringIgnoringCase(self::LEAKED, $url);
        $this->assertStringNotContainsStringIgnoringCase($hash, $url);
        $this->assertStringNotContainsStringIgnoringCase(\substr($hash, 5), $url);

        // Only the first five characters of the hash go out, so the service cannot tell which password was checked
        $this->assertStringEndsWith(\substr($hash, 0, 5), $url);
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

    public function testUnreachableServiceIsReported(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $validator = $this->validator([], $adapter);

        try {
            $validator->isValid(self::LEAKED);
            $this->fail('Expected the unreachable service to surface');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
        }
    }

    public function testUnexpectedStatusIsReported(): void
    {
        $adapter = new RangeAdapter(statusCode: 503, body: '');
        $validator = $this->validator([], $adapter);

        $this->expectException(Exception::class);

        $validator->isValid(self::LEAKED);
    }

    public function testFailedLookupIsNotCached(): void
    {
        $adapter = new RangeAdapter(failure: new \RuntimeException('connection refused'));
        $cache = new Cache(new Memory());
        $validator = new PasswordPwned(['enabled' => true], $cache, self::ENDPOINT, new Client($adapter));

        foreach ([1, 2] as $attempt) {
            try {
                $validator->isValid(self::LEAKED);
            } catch (Exception) {
                // The lookup failed, which is the point; it must be retried rather than remembered
            }
        }

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

    public function testLookupsGoToTheConfiguredEndpoint(): void
    {
        $adapter = new RangeAdapter(body: '');

        (new PasswordPwned(['enabled' => true], null, 'https://server.test/range', new Client($adapter)))->isValid(self::LEAKED);
        (new PasswordPwned(['enabled' => true], null, 'https://other.test/range/', new Client($adapter)))->isValid(self::LEAKED);

        $this->assertStringStartsWith('https://server.test/range/', $adapter->requests[0]);
        $this->assertStringStartsWith('https://other.test/range/', $adapter->requests[1]);
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
    /** @var array<int, string> URLs the validator asked the breach service for */
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
        $this->requests[] = $url;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response($this->statusCode, $this->body, []);
    }
}
