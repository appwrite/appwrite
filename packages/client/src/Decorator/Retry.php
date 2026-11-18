<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator;
use Utopia\Client\Decorator\Retry\Attempt;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Decorator\Retry\Strategy;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Psr18\ResponseStreamingClientInterface;

/**
 * An adapter that decorates another adapter and retries it according to a
 * Strategy. The strategy owns every policy decision (which outcomes to retry, how
 * long to wait, when to stop); this decorator only loops, sleeps, and holds back
 * streamed error bodies until the strategy has decided. Configuration forwarding is
 * inherited from Decorator.
 */
final class Retry extends Decorator implements ResponseStreamingClientInterface
{
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

    #[\Override]
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

        for ($number = 1; ; $number++) {
            $attempt = new Attempt($sink(...));

            try {
                $response = $adapter instanceof ResponseStreamingClientInterface
                    ? $adapter->stream($request, $attempt->write(...), $this->observer($attempt, $onResponse))
                    : $this->adapter->stream($request, $attempt->deliver(...));

                if ($attempt->hasDelivered()) {
                    return $response;
                }

                $delay = $this->strategy->delay($request, $number, $attempt->withHeldBody($response), null);

                if ($delay === null) {
                    $attempt->release();

                    return $response;
                }
            } catch (ClientExceptionInterface $error) {
                if ($attempt->hasDelivered()) {
                    throw $error;
                }

                $delay = $this->strategy->delay($request, $number, null, $error);

                if ($delay === null) {
                    throw $error;
                }
            } finally {
                $attempt->close();
            }

            ($this->sleep)($delay);
        }
    }

    private function responseStreamingAdapter(): ?ResponseStreamingClientInterface
    {
        $adapter = $this->adapter;

        return $adapter instanceof ResponseStreamingClientInterface && $adapter->supportsResponseStreaming() ? $adapter : null;
    }

    /**
     * @param (callable(ResponseInterface): void)|null $onResponse
     *
     * @return Closure(ResponseInterface): void
     */
    private function observer(Attempt $attempt, ?callable $onResponse): Closure
    {
        return static function (ResponseInterface $response) use ($attempt, $onResponse): void {
            $attempt->observe($response);

            if ($onResponse !== null) {
                $onResponse($response);
            }
        };
    }
}
