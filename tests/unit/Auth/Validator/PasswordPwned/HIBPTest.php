<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned\HIBP;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

final class HIBPTest extends TestCase
{
    private const LEAKED = 'Password123!';
    private const ENDPOINT = 'https://breaches.test/range';

    public function testLeakedPasswordIsRejected(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 42]));

        $this->assertFalse($this->validator($fetch)->isValid(self::LEAKED));
    }

    public function testCleanPasswordIsAccepted(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 42]));

        $this->assertTrue($this->validator($fetch)->isValid('never-leaked-' . \uniqid()));
    }

    public function testAnySingleBreachIsEnough(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 1]));

        $this->assertFalse($this->validator($fetch)->isValid(self::LEAKED));
    }

    public function testPaddedEntryWithZeroCountIsNotABreach(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 0]));

        $this->assertTrue($this->validator($fetch)->isValid(self::LEAKED));
    }

    public function testSuffixComparisonIgnoresCase(): void
    {
        $fetch = new RangeFetch(body: \strtolower($this->range([self::LEAKED => 3])));

        $this->assertFalse($this->validator($fetch)->isValid(self::LEAKED));
    }

    public function testOnlyTheHashPrefixLeavesTheServer(): void
    {
        $fetch = new RangeFetch(body: '');

        $this->validator($fetch)->isValid(self::LEAKED);

        $this->assertCount(1, $fetch->urls);

        $hash = \strtoupper(\sha1(self::LEAKED));
        $url = $fetch->urls[0];

        // Neither the password nor anything that identifies it may reach the service
        $this->assertStringNotContainsStringIgnoringCase(self::LEAKED, $url);
        $this->assertStringNotContainsStringIgnoringCase($hash, $url);
        $this->assertStringNotContainsStringIgnoringCase(\substr($hash, 5), $url);

        // Only the first five characters go out, so the service cannot tell which password was checked
        $this->assertStringEndsWith(\substr($hash, 0, 5), $url);
    }

    public function testRangeIsCachedPerPrefix(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 42]));
        $cache = new Cache(new Memory());

        $this->assertFalse((new HIBP($cache, new Client($fetch), self::ENDPOINT))->isValid(self::LEAKED));
        $this->assertFalse((new HIBP($cache, new Client($fetch), self::ENDPOINT))->isValid(self::LEAKED));

        $this->assertCount(1, $fetch->urls);
    }

    public function testCacheIsScopedToEndpoint(): void
    {
        $fetch = new RangeFetch(body: $this->range([self::LEAKED => 42]));
        $cache = new Cache(new Memory());

        (new HIBP($cache, new Client($fetch), 'https://one.test/range'))->isValid(self::LEAKED);
        (new HIBP($cache, new Client($fetch), 'https://two.test/range'))->isValid(self::LEAKED);

        $this->assertCount(2, $fetch->urls);
    }

    public function testLookupsGoToTheConfiguredEndpoint(): void
    {
        $fetch = new RangeFetch(body: '');

        (new HIBP(null, new Client($fetch), 'https://server.test/range'))->isValid(self::LEAKED);
        (new HIBP(null, new Client($fetch), 'https://other.test/range/'))->isValid(self::LEAKED);

        $this->assertStringStartsWith('https://server.test/range/', $fetch->urls[0]);
        $this->assertStringStartsWith('https://other.test/range/', $fetch->urls[1]);
    }

    public function testUnreachableServiceIsReported(): void
    {
        $fetch = new RangeFetch(failure: new \RuntimeException('connection refused'));

        try {
            $this->validator($fetch)->isValid(self::LEAKED);
            $this->fail('Expected the unreachable service to surface');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE, $e->getType());
        }
    }

    public function testUnexpectedStatusIsReported(): void
    {
        $fetch = new RangeFetch(statusCode: 503, body: '');

        $this->expectException(Exception::class);

        $this->validator($fetch)->isValid(self::LEAKED);
    }

    public function testFailedLookupIsNotCached(): void
    {
        $fetch = new RangeFetch(failure: new \RuntimeException('connection refused'));
        $validator = new HIBP(new Cache(new Memory()), new Client($fetch), self::ENDPOINT);

        foreach ([1, 2] as $attempt) {
            try {
                $validator->isValid(self::LEAKED);
            } catch (Exception) {
                // The lookup failed, which is the point; it must be retried rather than remembered
            }
        }

        $this->assertCount(2, $fetch->urls);
    }

    private function validator(RangeFetch $fetch): HIBP
    {
        return new HIBP(null, new Client($fetch), self::ENDPOINT);
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

final class RangeFetch implements Adapter
{
    /** @var array<int, string> URLs the validator asked the breach service for */
    public array $urls = [];

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
        $this->urls[] = $url;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response($this->statusCode, $this->body, []);
    }
}
