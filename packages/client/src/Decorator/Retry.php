<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Decorator\Retry\Strategy;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Psr18\ResponseStreamingClientInterface;
use Utopia\Psr7\Stream;

/**
 * An adapter that decorates another adapter and retries it according to a
 * Strategy. The strategy owns every policy decision (which outcomes to retry, how
 * long to wait, when to stop); this decorator only loops, sleeps, and holds back
 * streamed error bodies until the strategy has decided. Configuration forwarding is
 * inherited from Decorator.
 */
final class Retry extends Decorator implements ResponseStreamingClientInterface
{
    private const int ERROR_STATUS = 400;

    private const int CHUNK_SIZE = 65_536;

    private readonly Closure $sleep;

    /**
     * @param (Closure(float): void)|null $sleep waits the given number of seconds; defaults to usleep()
     */
    public function __construct(
        Adapter $adapter,
        private readonly Strategy $strategy = new Backoff(),
        ?Closure $sleep = null,
    ) {
        parent::__construct($adapter);

        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->adapter->sendRequest($request);
                $delay = $this->strategy->delay($request, $attempt, $response, null);

                if ($delay === null) {
                    return $response;
                }
            } catch (ClientExceptionInterface $error) {
                $delay = $this->strategy->delay($request, $attempt, null, $error);

                if ($delay === null) {
                    throw $error;
                }
            }

            ($this->sleep)($delay);
        }
    }

    public function supportsResponseStreaming(): bool
    {
        return $this->responseStreamingAdapter() instanceof ResponseStreamingClientInterface;
    }

    #[\Override]
    public function stream(RequestInterface $request, callable $sink, ?callable $onResponse = null): ResponseInterface
    {
        $adapter = $this->responseStreamingAdapter();
        if ($onResponse !== null && !$adapter instanceof ResponseStreamingClientInterface) {
            throw new AdapterPreconditionException($request, 'This adapter cannot expose response headers before streaming.');
        }

        for ($attempt = 1; ; $attempt++) {
            $delivered = 0;
            $held = null;
            $countingSink = static function (string $chunk) use ($sink, &$delivered): void {
                $delivered += \strlen($chunk);
                $sink($chunk);
            };

            try {
                $response = $adapter instanceof ResponseStreamingClientInterface
                    ? $this->streamHoldingErrors($adapter, $request, $countingSink, $onResponse, $held)
                    : $this->adapter->stream($request, $countingSink);

                // Once bytes have reached the sink, replaying would duplicate them.
                if ($delivered > 0) {
                    return $response;
                }

                $delay = $this->strategy->delay($request, $attempt, $this->withHeldBody($response, $held), null);

                if ($delay === null) {
                    $this->release($held, $sink);

                    return $response;
                }
            } catch (ClientExceptionInterface $error) {
                if ($delivered > 0) {
                    throw $error;
                }

                $delay = $this->strategy->delay($request, $attempt, null, $error);

                if ($delay === null) {
                    throw $error;
                }
            }

            $held?->close();
            ($this->sleep)($delay);
        }
    }

    private function responseStreamingAdapter(): ?ResponseStreamingClientInterface
    {
        $adapter = $this->adapter;

        return $adapter instanceof ResponseStreamingClientInterface && $adapter->supportsResponseStreaming() ? $adapter : null;
    }

    /**
     * Error responses (4xx/5xx) write their body to $held instead of the sink, so a
     * proxy error page does not count as delivered and the strategy can still retry.
     *
     * @param callable(string): void                   $sink
     * @param (callable(ResponseInterface): void)|null $onResponse
     *
     * @param-out StreamInterface|null $held
     *
     * @throws ClientExceptionInterface
     */
    private function streamHoldingErrors(
        ResponseStreamingClientInterface $adapter,
        RequestInterface $request,
        callable $sink,
        ?callable $onResponse,
        ?StreamInterface &$held,
    ): ResponseInterface {
        $observe = static function (ResponseInterface $response) use ($onResponse, &$held): void {
            if ($response->getStatusCode() >= self::ERROR_STATUS) {
                $held = new Stream();
            }

            if ($onResponse !== null) {
                $onResponse($response);
            }
        };

        $write = static function (string $chunk) use ($sink, &$held): void {
            if ($held instanceof StreamInterface) {
                $held->write($chunk);

                return;
            }

            $sink($chunk);
        };

        return $adapter->stream($request, $write, $observe);
    }

    private function withHeldBody(ResponseInterface $response, ?StreamInterface $held): ResponseInterface
    {
        if (!$held instanceof StreamInterface) {
            return $response;
        }

        $held->rewind();

        return $response->withBody($held);
    }

    /**
     * @param callable(string): void $sink
     */
    private function release(?StreamInterface $held, callable $sink): void
    {
        if (!$held instanceof StreamInterface) {
            return;
        }

        $held->rewind();
        while (!$held->eof()) {
            $chunk = $held->read(self::CHUNK_SIZE);
            if ($chunk !== '') {
                $sink($chunk);
            }
        }

        $held->close();
    }
}
