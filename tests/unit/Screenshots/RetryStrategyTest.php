<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots;

use Appwrite\Screenshots\RetryStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Exception\NetworkException;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;
use Utopia\Psr7\Response;

final class RetryStrategyTest extends TestCase
{
    private const int UNAVAILABLE = 503;

    public function testRetriesUnavailableAfterRetryAfter(): void
    {
        $this->assertEqualsWithDelta(7.0, $this->strategy(random: 0.0)->delay($this->request(), 1, $this->unavailable('7'), null), PHP_FLOAT_EPSILON);
    }

    /**
     * @return \Iterator<string, array{float, float}>
     */
    public static function jitters(): \Iterator
    {
        yield 'no jitter drawn' => [0.0, 4.0];
        yield 'half the jitter window' => [0.5, 5.0];
        yield 'almost the whole jitter window' => [0.99, 5.98];
    }

    #[DataProvider('jitters')]
    public function testTreatsRetryAfterAsALowerBoundAndAddsJitter(float $random, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $this->strategy(random: $random)->delay($this->request(), 1, $this->unavailable('4'), null), 1e-9);
    }

    public function testCapsJitteredDelayAtMaxDelay(): void
    {
        $this->assertEqualsWithDelta(10.0, $this->strategy(random: 0.99)->delay($this->request(), 1, $this->unavailable('8'), null), PHP_FLOAT_EPSILON);
    }

    public function testRetriesAtMaxDelayWhenRetryAfterEqualsIt(): void
    {
        $this->assertEqualsWithDelta(10.0, $this->strategy(random: 0.0)->delay($this->request(), 1, $this->unavailable('10'), null), PHP_FLOAT_EPSILON);
    }

    public function testSurfacesFailureWhenRetryAfterExceedsMaxDelay(): void
    {
        $this->assertNull($this->strategy(random: 0.0)->delay($this->request(), 1, $this->unavailable('30'), null));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function immediateRetryAfters(): \Iterator
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
    }

    #[DataProvider('immediateRetryAfters')]
    public function testRetriesImmediatelyWhenRetryAfterIsNotPositive(string $retryAfter): void
    {
        $this->assertEqualsWithDelta(0.0, $this->strategy(random: 0.99)->delay($this->request(), 1, $this->unavailable($retryAfter), null), PHP_FLOAT_EPSILON);
    }

    public function testDoesNotRetryUnavailableWithoutRetryAfter(): void
    {
        $this->assertNull((new RetryStrategy())->delay($this->request(), 1, new Response(self::UNAVAILABLE), null));
    }

    public function testDoesNotRetryNonNumericRetryAfter(): void
    {
        $this->assertNull((new RetryStrategy())->delay($this->request(), 1, $this->unavailable('Wed, 21 Oct 2026 07:28:00 GMT'), null));
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function terminalStatuses(): \Iterator
    {
        yield 'memory budget exceeded' => [507];
        yield 'blocked url' => [403];
        yield 'bad request' => [400];
        yield 'success' => [200];
    }

    #[DataProvider('terminalStatuses')]
    public function testDoesNotRetryOtherStatuses(int $status): void
    {
        $response = (new Response($status))->withHeader(Header::RETRY_AFTER, '7');

        $this->assertNull((new RetryStrategy())->delay($this->request(), 1, $response, null));
    }

    public function testDoesNotRetryTransportErrors(): void
    {
        $request = $this->request();

        $this->assertNull((new RetryStrategy())->delay($request, 1, null, new NetworkException($request, 'reset')));
    }

    public function testStopsWhenAttemptsAreSpent(): void
    {
        $this->assertNull((new RetryStrategy())->delay($this->request(), 3, $this->unavailable('1'), null));
    }

    public function testHonoursConfiguredAttempts(): void
    {
        $strategy = new RetryStrategy(attempts: 2, randomizer: static fn (): float => 0.0);

        $this->assertEqualsWithDelta(1.0, $strategy->delay($this->request(), 1, $this->unavailable('1'), null), PHP_FLOAT_EPSILON);
        $this->assertNull($strategy->delay($this->request(), 2, $this->unavailable('1'), null));
    }

    public function testDefaultRandomizerStaysWithinTheJitterWindow(): void
    {
        $delay = (new RetryStrategy(jitter: 1.0))->delay($this->request(), 1, $this->unavailable('2'), null);

        $this->assertNotNull($delay);
        $this->assertGreaterThanOrEqual(2.0, $delay);
        $this->assertLessThanOrEqual(4.0, $delay);
    }

    /**
     * @return \Iterator<string, array{int, float, float, string}>
     */
    public static function invalidConfigurations(): \Iterator
    {
        yield 'no attempts' => [0, 10.0, 0.5, 'Attempts must be at least 1, got 0.'];
        yield 'negative attempts' => [-1, 10.0, 0.5, 'Attempts must be at least 1, got -1.'];
        yield 'negative max delay' => [3, -1.0, 0.5, 'Max delay must be a non-negative number, got -1.0.'];
        yield 'max delay not a number' => [3, \NAN, 0.5, 'Max delay must be a non-negative number, got NAN.'];
        yield 'negative jitter' => [3, 10.0, -0.1, 'Jitter must be a non-negative number, got -0.1.'];
        yield 'jitter not a number' => [3, 10.0, \NAN, 'Jitter must be a non-negative number, got NAN.'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(int $attempts, float $maxDelay, float $jitter, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RetryStrategy($attempts, $maxDelay, $jitter);
    }

    public function testAcceptsSingleAttemptAndZeroMaxDelayAndJitter(): void
    {
        $strategy = new RetryStrategy(attempts: 1, maxDelay: 0.0, jitter: 0.0);

        $this->assertNull($strategy->delay($this->request(), 1, $this->unavailable('0'), null));
    }

    private function strategy(float $random): RetryStrategy
    {
        return new RetryStrategy(jitter: 0.5, randomizer: static fn (): float => $random);
    }

    private function request(): RequestInterface
    {
        return (new Factory())->json(Method::POST, 'screenshots', ['url' => 'http://appwrite/']);
    }

    private function unavailable(string $retryAfter): ResponseInterface
    {
        return (new Response(self::UNAVAILABLE))->withHeader(Header::RETRY_AFTER, $retryAfter);
    }
}
