<?php

declare(strict_types=1);

namespace Utopia\NATS\Exception;

/**
 * The server answered a request with 503: nothing is subscribed to the subject.
 *
 * For a JetStream publish that means no stream is taking the subject right now --
 * none binds it, or the one that does has no leader while its peers elect one,
 * which is what a server restarting, draining or being evicted looks like from
 * the client. Unlike a refusal, the same request can succeed moments later.
 */
class NoRespondersException extends NatsException
{
}
