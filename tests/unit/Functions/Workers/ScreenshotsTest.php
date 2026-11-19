<?php

declare(strict_types=1);

namespace Tests\Unit\Functions\Workers;

use Appwrite\Screenshots\Client;
use Appwrite\Screenshots\Exception as ScreenshotsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Unit\Functions\Workers\Fakes\Screenshots;
use Tests\Unit\Screenshots\Fakes\Adapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class ScreenshotsTest extends TestCase
{
    private const int FORBIDDEN = 403;

    private const int UNAVAILABLE = 503;

    private const int INSUFFICIENT_STORAGE = 507;

    private const string CAPACITY_EXCEEDED = 'Browser is at capacity. Retry after the Retry-After delay.';

    private const string CAPTURE_EVICTED = 'Capture was closed to keep the browser within its memory budget. Retry after the Retry-After delay.';

    private const string MEMORY_BUDGET_EXCEEDED = 'Page exceeded the memory budget and was closed.';

    /**
     * @return \Iterator<string, array{\Throwable}>
     */
    public static function permanentFailures(): \Iterator
    {
        yield 'page alone over the memory budget' => [new ScreenshotsException(self::MEMORY_BUDGET_EXCEEDED, self::INSUFFICIENT_STORAGE)];
        yield 'blocked url' => [new ScreenshotsException("Host '10.0.0.1' is not publicly routable.", self::FORBIDDEN)];
        yield 'unroutable host from an older browser image' => [new ScreenshotsException("Host '10.0.0.1' is not publicly routable.", 400)];
        yield 'permanent failure' => [new PermanentFailure('Rule for deployment not found')];
    }

    #[DataProvider('permanentFailures')]
    public function testAbandonsPermanentFailuresOnTheFirstAttempt(\Throwable $error): void
    {
        $this->assertTrue((new Screenshots())->isAbandoned($error, $this->message(attempts: 0, maxAttempts: 2)));
    }

    /**
     * @return \Iterator<string, array{string, int|null}>
     */
    public static function retryableDeliveries(): \Iterator
    {
        yield 'rejected at admission, NATS first attempt' => [self::CAPACITY_EXCEEDED, 2];
        yield 'evicted under load, NATS first attempt' => [self::CAPTURE_EVICTED, 2];
        yield 'rejected at admission, Redis' => [self::CAPACITY_EXCEEDED, null];
    }

    #[DataProvider('retryableDeliveries')]
    public function testRethrowsBrowserAtCapacityBeforeTheLastAttempt(string $reason, ?int $maxAttempts): void
    {
        $error = new ScreenshotsException($reason, self::UNAVAILABLE);

        $this->assertFalse((new Screenshots())->isAbandoned($error, $this->message(attempts: 0, maxAttempts: $maxAttempts)));
    }

    public function testAbandonsBrowserAtCapacityOnTheLastAttempt(): void
    {
        $error = new ScreenshotsException(self::CAPACITY_EXCEEDED, self::UNAVAILABLE);

        $this->assertTrue((new Screenshots())->isAbandoned($error, $this->message(attempts: 1, maxAttempts: 2)));
    }

    public function testCapturesBothThemes(): void
    {
        $adapter = new Adapter([$this->image('light-png'), $this->image('dark-png')]);

        $captures = $this->captureThemes($adapter);

        $this->assertSame(['screenshotLight' => 'light-png', 'screenshotDark' => 'dark-png'], $captures);
    }

    public function testBrowserAtCapacitySurfacesAsRetryable(): void
    {
        $adapter = new Adapter([$this->failure(self::UNAVAILABLE, self::CAPACITY_EXCEEDED), $this->failure(self::UNAVAILABLE, self::CAPACITY_EXCEEDED)]);

        $error = $this->captureFailure($adapter);

        $this->assertSame(self::UNAVAILABLE, $error->getCode());
        $this->assertFalse($error->isPermanent());
        $this->assertFalse((new Screenshots())->isAbandoned($error, $this->message(attempts: 0, maxAttempts: 2)));
    }

    public function testPrefersAPermanentFailureFromEitherTheme(): void
    {
        $adapter = new Adapter([$this->failure(self::UNAVAILABLE, self::CAPTURE_EVICTED), $this->failure(self::INSUFFICIENT_STORAGE, self::MEMORY_BUDGET_EXCEEDED)]);

        $error = $this->captureFailure($adapter);

        $this->assertSame(2, $adapter->calls);
        $this->assertSame(self::INSUFFICIENT_STORAGE, $error->getCode());
        $this->assertTrue((new Screenshots())->isAbandoned($error, $this->message(attempts: 0, maxAttempts: 2)));
    }

    /**
     * @return array<string, string>
     */
    private function captureThemes(Adapter $adapter): array
    {
        $duration = (new NoTelemetry())->createHistogram('worker.screenshots.capture.duration', 's');

        return (new Screenshots())->captureThemes(new Client($adapter), $duration, 'https://site.appwrite.network/', [], 0);
    }

    private function captureFailure(Adapter $adapter): ScreenshotsException
    {
        try {
            $this->captureThemes($adapter);
        } catch (ScreenshotsException $error) {
            return $error;
        }

        $this->fail('Expected the capture to fail.');
    }

    private function message(int $attempts, ?int $maxAttempts): Message
    {
        return (new Message(['pid' => 'pid', 'queue' => 'v1-screenshots', 'timestamp' => \time(), 'payload' => []]))
            ->setAttempts($attempts)
            ->setMaxAttempts($maxAttempts);
    }

    private function image(string $bytes): ResponseInterface
    {
        return (new Response(200, body: new Stream($bytes)))->withHeader(Header::CONTENT_TYPE, 'image/png');
    }

    private function failure(int $status, string $reason): ResponseInterface
    {
        return (new Response($status, body: new Stream(\json_encode(['error' => $reason], JSON_THROW_ON_ERROR))))
            ->withHeader(Header::CONTENT_TYPE, 'application/json');
    }
}
