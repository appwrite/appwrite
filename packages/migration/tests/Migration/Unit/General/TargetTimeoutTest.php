<?php

namespace Utopia\Tests\Unit\General;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Exception;
use Utopia\Tests\Unit\General\TargetTimeoutServerBehaviour as Behaviour;

final class TargetTimeoutTest extends TestCase
{
    private const string HTTP = 'http';

    private const string HTTPS = 'https';

    private const float GIVE_UP_WITHIN_SECONDS = 10.0;

    private const float TIMER_TOLERANCE_SECONDS = 0.1;

    /** @var resource|null */
    private $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \proc_terminate($this->server, 9);
            \proc_close($this->server);
        }

        $this->server = null;
    }

    public function testTransferOutlastingEveryTimeoutSucceedsWhileDataKeepsArriving(): void
    {
        $url = $this->serve(Behaviour::Streaming, self::HTTP);

        $started = \microtime(true);
        $body = (new ShortTimeoutTarget())->fetch($url);
        $elapsed = \microtime(true) - $started;

        $this->assertSame(\str_repeat('x', Behaviour::STREAMED_BYTES), $body);
        $this->assertGreaterThan(
            \max(ShortTimeoutTarget::REQUEST_TIMEOUT, ShortTimeoutTarget::STALL_TIMEOUT),
            $elapsed,
            'The transfer must outlast the request and stall timeouts to show neither caps a progressing transfer.',
        );
    }

    /**
     * @return array<string, array{Behaviour, string, int, float}>
     */
    public static function silentRemotes(): array
    {
        return [
            'accepts and never answers' => [Behaviour::Silent, self::HTTP, ShortTimeoutTarget::STALL_TIMEOUT, self::GIVE_UP_WITHIN_SECONDS],
            'stops sending mid-body' => [Behaviour::Stalled, self::HTTP, ShortTimeoutTarget::STALL_TIMEOUT, self::GIVE_UP_WITHIN_SECONDS],
            'never completes the connection' => [Behaviour::Silent, self::HTTPS, ShortTimeoutTarget::CONNECT_TIMEOUT, ShortTimeoutTarget::STALL_TIMEOUT],
        ];
    }

    #[DataProvider('silentRemotes')]
    public function testSilentRemoteFailsOnItsTimeout(Behaviour $behaviour, string $scheme, int $timeout, float $before): void
    {
        $url = $this->serve($behaviour, $scheme);

        $started = \microtime(true);
        $error = null;

        try {
            (new ShortTimeoutTarget())->fetch($url);
        } catch (\Exception $caught) {
            $error = $caught;
        }

        $elapsed = \microtime(true) - $started;

        $this->assertInstanceOf(\Exception::class, $error, 'A remote that sends nothing must fail the request.');
        $this->assertSame(Exception::CODE_INTERNAL, $error->getCode(), $error->getMessage());
        $this->assertGreaterThanOrEqual(
            $timeout - self::TIMER_TOLERANCE_SECONDS,
            $elapsed,
            'The request must wait out its timeout rather than fail for another reason: '.$error->getMessage(),
        );
        $this->assertLessThan($before, $elapsed, 'The request must fail on its own timeout: '.$error->getMessage());
    }

    private function serve(Behaviour $behaviour, string $scheme): string
    {
        $script = \dirname(__DIR__, 2).'/resources/timeout/server.php';
        $server = \proc_open([PHP_BINARY, $script, $behaviour->value], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($server);
        $this->server = $server;

        $port = \trim((string) \fgets($pipes[1]));
        $this->assertMatchesRegularExpression('/^\d+$/', $port, 'The test server did not report its port.');

        return "{$scheme}://127.0.0.1:{$port}/";
    }
}
