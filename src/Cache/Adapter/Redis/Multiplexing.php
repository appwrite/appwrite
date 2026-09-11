<?php

namespace Utopia\Cache\Adapter\Redis;

use SplQueue;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Lock;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Telemetry as TelemetryFeature;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\UpDownCounter;

/**
 * Redis\Multiplexing adapter.
 *
 * Multiplexes many Swoole coroutines over a single Redis TCP connection. Each
 * caller takes a connection-wide Lock, pushes its response Channel onto the
 * FIFO `pending` queue, sends its RESP frame, and releases the lock — so the
 * order of registrations exactly matches the order of bytes on the wire. A
 * single reader coroutine parses inbound frames and dispatches each one to
 * the next pending Channel, exploiting Redis's guarantee of in-order replies.
 */
class Multiplexing extends Leasable implements Adapter, Batchable, TelemetryFeature
{
    private ?ConnectionContext $connection = null;

    /**
     * Serializes pending enqueue + send so the FIFO invariant holds even when
     * many coroutines issue commands concurrently.
     */
    private readonly Lock $sendLock;

    private Telemetry $telemetry;

    private ?UpDownCounter $pendingDepth = null;

    /**
     * @param  float  $timeout connect timeout in seconds
     * @param  float  $readTimeout per-call read deadline in seconds — how long
     *                             one caller waits for its own reply before
     *                             giving up. Caches should fail fast, default
     *                             0.25s. Expiring fails that call only; it is
     *                             not a verdict on the connection.
     * @param  float  $livenessTimeout how long the reader may make no progress
     *                                 at all, with callers still waiting, before
     *                                 the connection is declared dead and torn
     *                                 down. This is the verdict that fails every
     *                                 pending caller, so it wants to be far
     *                                 larger than $readTimeout: a socket that has
     *                                 gone silent is indistinguishable from a
     *                                 server that is merely busy until enough
     *                                 time has passed. Default 5s.
     * @param  string|array<string>|null  $auth password or [username, password]
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 6379,
        private readonly float $timeout = 1.0,
        private readonly float $readTimeout = 0.25,
        private readonly string|array|null $auth = null,
        private readonly int $dbIndex = 0,
        private readonly float $livenessTimeout = 5.0,
    ) {
        if ($this->timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }
        if ($this->readTimeout <= 0) {
            throw new \InvalidArgumentException('readTimeout must be greater than 0');
        }
        if ($this->livenessTimeout < $this->readTimeout) {
            throw new \InvalidArgumentException('livenessTimeout must be greater than or equal to readTimeout');
        }
        $this->sendLock = new Lock();
        $this->setTelemetry(new NoTelemetry());

        $locked = $this->lockSend();
        try {
            $this->connect();
        } finally {
            $this->unlockSend($locked);
        }
    }

    /**
     * Wire a telemetry adapter for connection-level metrics. The pending-queue
     * depth is tracked as an up/down counter — incremented on enqueue and
     * decremented on dequeue — so a steady-state non-zero value means callers
     * are queueing faster than Redis is replying.
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        $this->pendingDepth = null;
    }

    private function getPendingDepth(): UpDownCounter
    {
        return $this->pendingDepth ??= $this->telemetry->createUpDownCounter(
            'cache.redis_multiplexing.pending.depth',
            description: 'Pending response channels awaiting RESP frames on the multiplexed connection.',
        );
    }

    /**
     * Explicitly close the multiplexed connection. Required for clean shutdown
     * because the reader coroutine holds a reference to this adapter.
     */
    public function disconnect(): void
    {
        $this->shutdown();
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        if ($this->isReserved($hash)) {
            return false;
        }

        $value = $this->command(['HGET', $key, $hash]);

        if (! \is_string($value)) {
            return false;
        }

        return Envelope::decode($value, $ttl, time());
    }

    /**
     * HMGET for an explicit field list, HGETALL when $fields is empty. Both come
     * back as one RESP array reply from the multiplexed reader.
     *
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        $now = time();
        $pairs = [];

        if ($fields === []) {
            $flat = $this->command(['HGETALL', $key]);
            if (! \is_array($flat)) {
                return [];
            }
            // HGETALL returns a flat [field, value, field, value, …] reply.
            $count = \count($flat);
            for ($i = 0; $i + 1 < $count; $i += 2) {
                $pairs[(string) $flat[$i]] = $flat[$i + 1];
            }
        } else {
            $values = $this->command(['HMGET', $key, ...$fields]);
            if (! \is_array($values)) {
                return [];
            }
            // HMGET returns values positional to the requested fields (null when absent).
            foreach (array_values($fields) as $i => $field) {
                $pairs[$field] = $values[$i] ?? null;
            }
        }

        $result = [];
        foreach ($pairs as $field => $value) {
            if (! \is_string($value)) {
                continue;
            }
            if ($this->isReserved((string) $field)) {
                continue;
            }

            $decoded = Envelope::decode($value, $ttl, $now);
            if ($decoded !== false) {
                $result[(string) $field] = $decoded;
            }
        }

        return $result;
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        if ($this->isReserved($hash)) {
            return false;
        }

        try {
            $value = Envelope::encode($data, time());
            $this->command(['HSET', $key, $hash, $value]);

            if ($ttl > 0) {
                $this->command(['EXPIRE', $key, (string) $ttl]);
            }

            return $data;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One variadic HSET for every field => value pair of $data, then one EXPIRE.
     *
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $args = ['HSET', $key];
        foreach ($data as $field => $value) {
            $field = (string) $field;
            if ($this->isReserved($field)) {
                continue;
            }
            $args[] = $field;
            $args[] = Envelope::encode($value, time());
        }

        if (\count($args) <= 2) {
            return false;
        }

        try {
            $this->command($args);

            if ($ttl > 0) {
                $this->command(['EXPIRE', $key, (string) $ttl]);
            }

            return $data;
        } catch (Throwable) {
            return false;
        }
    }

    public function touch(string $key, string $hash = ''): bool
    {
        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        if ($this->isReserved($hash)) {
            return false;
        }

        $value = $this->command(['HGET', $key, $hash]);
        if (! \is_string($value)) {
            return false;
        }

        $payload = Envelope::touch($value, time());
        if ($payload === false) {
            return false;
        }

        return $this->command(['HSET', $key, $hash, $payload]) !== false;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        $keys = $this->command(['HKEYS', $key]);
        if (! \is_array($keys)) {
            return [];
        }

        /** @var string[] $keys */
        return array_values(array_filter($keys, fn(string $field): bool => ! $this->isReserved($field)));
    }

    protected function leaseEvalSha(string $sha, string $key, array $args): mixed
    {
        try {
            return $this->command(['EVALSHA', $sha, '1', $key, ...$args]);
        } catch (\RedisException $e) {
            // NOSCRIPT is a server reply, not a connection fault: signal leaseRun()
            // to resend the body. ConnectionException (a RedisException subclass)
            // never carries this code, so reconnect handling is unaffected.
            if (NoScript::matches($e->getMessage())) {
                throw NoScript::from($e);
            }

            throw $e;
        }
    }

    protected function leaseEval(string $script, string $key, array $args): mixed
    {
        return $this->command(['EVAL', $script, '1', $key, ...$args]);
    }

    protected function leaseHget(string $key, string $field): mixed
    {
        return $this->command(['HGET', $key, $field]);
    }

    public function flush(): bool
    {
        return $this->command(['FLUSHDB']) === 'OK';
    }

    public function ping(): bool
    {
        try {
            return $this->command(['PING']) === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    public function getSize(): int
    {
        $size = $this->command(['DBSIZE']);

        return \is_int($size) ? $size : 0;
    }

    public function getName(?string $key = null): string
    {
        return 'redis-multiplexing';
    }

    /**
     * Send a Redis command and block the calling coroutine until the response arrives.
     * On a connection error, transparently reconnects once and retries — a failed
     * connection drops every in-flight caller, so the only sensible recovery is to
     * rebuild it before failing the call.
     *
     * An expired read deadline is not retried. It means the server was slow, and the
     * connection is still good; reconnecting and resending would put a second copy of
     * the same command on an already-struggling server, once per caller.
     *
     * @param  array<int|string>  $args
     */
    private function command(array $args): mixed
    {
        try {
            return $this->dispatch($args);
        } catch (TimeoutException|IdleConnectionException $unretryable) {
            // Caught ahead of their parent to opt out of the retry below. Neither
            // is fixed by resending: the server is slow, or it has answered
            // nothing for seconds and the connection has just been replaced.
            // Resending would add a second copy of this command, per caller, to a
            // server that is already failing to keep up.
            throw $unretryable;
        } catch (ConnectionException) {
            $this->ensureConnected();

            return $this->dispatch($args);
        }
    }

    /**
     * Send a single command on the current connection and wait for its response.
     * Acquires the send lock; do not call from a context that already holds it.
     *
     * @param  array<int|string>  $args
     */
    private function dispatch(array $args): mixed
    {
        $locked = $this->lockSend();
        try {
            $context = $this->connection;
            if (!$context instanceof \Utopia\Cache\Adapter\Redis\ConnectionContext) {
                throw new ConnectionException('Redis connection is not open');
            }
            $response = new Channel(1);
            $error = null;

            $context->pending->enqueue($response);
            $this->getPendingDepth()->add(1);
            try {
                $context->client->send(Client::encode($args));
            } catch (ConnectionException $sendError) {
                $error = $sendError;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($error instanceof \Utopia\Cache\Adapter\Redis\ConnectionException) {
            $this->teardownIfCurrent($context, $error);

            throw $error;
        }

        return $this->awaitResponse($context, $response);
    }

    private function ensureConnected(): void
    {
        $locked = $this->lockSend();
        try {
            if (!$this->connection instanceof \Utopia\Cache\Adapter\Redis\ConnectionContext) {
                $this->connect();
            }
        } finally {
            $this->unlockSend($locked);
        }
    }

    /**
     * @param  Channel<mixed>  $response
     */
    private function awaitResponse(ConnectionContext $context, Channel $response): mixed
    {
        $result = $response->pop($this->readTimeout);
        if ($result === false && $response->errCode !== 0) {
            $idleFor = $context->idleFor();

            // The reader has gone quiet for longer than a busy server explains,
            // and callers are still queued: treat the connection as dead so the
            // next caller rebuilds it. Without this a socket that never returns
            // from recv() — blackholed rather than reset — would strand every
            // caller forever, because the reader has no deadline of its own.
            if ($idleFor >= $this->livenessTimeout) {
                $error = new IdleConnectionException(\sprintf(
                    'Redis connection idle for %.3fs with responses outstanding',
                    $idleFor,
                ));
                $this->teardownIfCurrent($context, $error);

                throw $error;
            }

            // Otherwise the server is just slower than this caller was willing to
            // wait. Fail this call and leave the connection alone.
            //
            // The response Channel deliberately stays on $pending. The FIFO
            // invariant is that the queue's order matches the order of frames on
            // the wire, so removing this slot would make the reader hand this
            // call's reply to the next caller — and every reply after it to the
            // wrong caller. Leaving it means the reader dequeues it in order and
            // pushes the late reply into a Channel nobody is reading; capacity is
            // 1, so the push cannot block, and the Channel is then collected. The
            // pending-depth counter is decremented by the reader as usual.
            throw new TimeoutException(\sprintf(
                'Timed out waiting for Redis response after %.3fs',
                $this->readTimeout,
            ));
        }

        return Client::unwrap($result);
    }

    /**
     * Caller must hold $sendLock when running inside a coroutine.
     */
    private function connect(): void
    {
        $client = new Client($this->host, $this->port, $this->timeout);

        try {
            if ($this->auth !== null) {
                $authArgs = \is_array($this->auth)
                    ? array_merge(['AUTH'], array_values($this->auth))
                    : ['AUTH', $this->auth];

                if ($client->command($authArgs, $this->readTimeout) !== 'OK') {
                    throw new \RedisException('Redis AUTH failed');
                }
            }

            if ($this->dbIndex !== 0 && $client->command(['SELECT', (string) $this->dbIndex], $this->readTimeout) !== 'OK') {
                throw new \RedisException('Redis SELECT failed');
            }
        } catch (Throwable $th) {
            $client->close();

            throw $th;
        }

        /** @var SplQueue<Channel<mixed>> $pending */
        $pending = new SplQueue();
        $context = new ConnectionContext($client, $pending);
        $this->connection = $context;

        Coroutine::create(function () use ($context): void {
            $this->readerLoop($context);
        });
    }

    private function shutdown(): void
    {
        $context = null;
        $locked = $this->lockSend();
        try {
            if ($this->connection instanceof \Utopia\Cache\Adapter\Redis\ConnectionContext) {
                $context = $this->connection;
                $this->connection = null;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($context instanceof \Utopia\Cache\Adapter\Redis\ConnectionContext) {
            $this->finishTeardown($context, new ConnectionException('Connection closed'));
        }
    }

    /**
     * Stop the connection and fail every coroutine still waiting on a response.
     */
    private function finishTeardown(ConnectionContext $context, ConnectionException $error): void
    {
        while (! $context->pending->isEmpty()) {
            $ch = $context->pending->dequeue();
            if ($ch instanceof Channel) {
                $this->getPendingDepth()->add(-1);
                $ch->push(new ConnectionError($error));
            }
        }

        $context->client->close();
    }

    private function teardownIfCurrent(ConnectionContext $context, ConnectionException $error): void
    {
        $shouldTeardown = false;
        $locked = $this->lockSend();
        try {
            if ($this->connection === $context) {
                $this->connection = null;
                $shouldTeardown = true;
            }
        } finally {
            $this->unlockSend($locked);
        }

        if ($shouldTeardown) {
            $this->finishTeardown($context, $error);
        }
    }

    private function lockSend(): bool
    {
        if (Coroutine::getCid() < 0) {
            return false;
        }

        $this->sendLock->lock();

        return true;
    }

    private function unlockSend(bool $locked): void
    {
        if ($locked) {
            $this->sendLock->unlock();
        }
    }

    private function readerLoop(ConnectionContext $context): void
    {
        $readBuffer = $context->client->takeBuffer();

        while (true) {
            while ($readBuffer !== '') {
                $offset = 0;
                try {
                    $value = Client::parse($readBuffer, $offset);
                } catch (Throwable $th) {
                    $this->teardownIfCurrent($context, new ConnectionException('Redis protocol parse failed: ' . $th->getMessage()));

                    return;
                }

                if ($value === Client::INCOMPLETE) {
                    break;
                }

                $readBuffer = substr($readBuffer, $offset);

                if (! $this->isCurrentContext($context)) {
                    return;
                }

                // A complete frame implies a prior pending enqueue.
                $waiting = $context->pending->isEmpty() ? null : $context->pending->dequeue();
                if ($waiting instanceof Channel) {
                    $this->getPendingDepth()->add(-1);
                    $context->recordProgress();
                    // May be a reply whose caller already gave up on its own
                    // deadline. The slot is still dequeued in order so the frames
                    // behind it stay aligned, and the push cannot block on a
                    // capacity-1 Channel, so an abandoned reply is simply dropped.
                    $waiting->push($value);
                } else {
                    // Should never happen given the send-lock invariant. Log
                    // and tear down so the next caller reconnects on a clean
                    // socket rather than continuing to misroute frames.
                    error_log('Redis\\Multiplexing: unexpected RESP frame with no pending request; tearing down connection');
                    $this->teardownIfCurrent($context, new ConnectionException('Unexpected RESP frame with no pending request'));

                    return;
                }
            }

            $chunk = $context->client->recv(-1);
            if (\is_string($chunk) && $chunk !== '') {
                // Bytes arriving is progress even before they complete a frame,
                // so a large reply streaming in slowly is not mistaken for a dead
                // connection.
                $context->recordProgress();
            }
            if ($chunk === false || $chunk === '') {
                $this->teardownIfCurrent($context, new ConnectionException('Redis connection closed'));

                return;
            }

            $readBuffer .= $chunk;
        }
    }

    private function isCurrentContext(ConnectionContext $context): bool
    {
        $locked = $this->lockSend();
        try {
            return $this->connection === $context;
        } finally {
            $this->unlockSend($locked);
        }
    }
}
