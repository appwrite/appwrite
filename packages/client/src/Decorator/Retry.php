<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Decorator\Retry\Strategy;

/**
 * An adapter that decorates another adapter and retries it according to a
 * Strategy. The strategy owns every policy decision (which outcomes to retry, how
 * long to wait, when to stop); this decorator only loops and sleeps. Configuration
 * forwarding is inherited from Decorator.
 */
final class Retry extends Decorator
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
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        for ($attempt = 1; ; $attempt++) {
            $delivered = 0;
            $countingSink = static function (string $chunk) use ($sink, &$delivered): void {
                $delivered += \strlen($chunk);
                $sink($chunk);
            };

            try {
                $response = $this->adapter->stream($request, $countingSink);

                // Once bytes have reached the sink, replaying would duplicate them.
                if ($delivered > 0) {
                    return $response;
                }

                $delay = $this->strategy->delay($request, $attempt, $response, null);

                if ($delay === null) {
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

            ($this->sleep)($delay);
        }
    }
}
