<?php

declare(strict_types=1);

namespace Utopia\NATS;

use Utopia\NATS\Auth\Authenticator;
use Utopia\NATS\Auth\CredentialsAuth;
use Utopia\NATS\Auth\NKeyAuth;
use Utopia\NATS\Auth\NoAuth;
use Utopia\NATS\Auth\TokenAuth;
use Utopia\NATS\Auth\UserPassAuth;
use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Internal\CallbackException;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Protocol\ServerOp;
use Utopia\NATS\Protocol\Writer;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\Transport;
use Utopia\NATS\Transport\WebSocketTransport;

final class Connection
{
    private const STATUS_DISCONNECTED = 'disconnected';
    private const STATUS_CONNECTING = 'connecting';
    private const STATUS_CONNECTED = 'connected';
    private const STATUS_RECONNECTING = 'reconnecting';
    private const STATUS_DRAINING = 'draining';
    private const STATUS_CLOSED = 'closed';

    private const CLIENT_LANG = 'php';
    private const CLIENT_VERSION = '0.1.0';

    // Bounds on the read tick() does to collect its own PONGs. Short enough
    // that a sweep over an idle pool stays in the low milliseconds, and capped
    // so a connection with live subscription traffic cannot hold maintenance.
    private const float TICK_READ_TIMEOUT = 0.05;
    private const int TICK_MAX_READS = 4;

    /**
     * Server -ERR substrings after which NATS closes the connection. Lowercase;
     * matched as substrings because the server wraps some of them in quotes.
     *
     * @var list<string>
     */
    private const array CLOSING_ERRORS = [
        'stale connection',
        'slow consumer',
        'maximum payload',
        'invalid client protocol',
        'authorization violation',
        'authentication timeout',
        'authentication expired',
        'secure connection - tls required',
        'maximum connections exceeded',
        'maximum control line exceeded',
        'unknown protocol operation',
        'parser error',
    ];

    /**
     * The subset of {@see self::CLOSING_ERRORS} a reconnect cannot fix, so the
     * connection is marked dead rather than rebuilt in a loop.
     *
     * @var list<string>
     */
    private const array UNRECOVERABLE_ERRORS = [
        'authorization violation',
        'authentication timeout',
        'authentication expired',
        'secure connection - tls required',
        'maximum connections exceeded',
        'maximum control line exceeded',
        'unknown protocol operation',
        'parser error',
    ];

    private Transport $transport;
    private Parser $parser;
    private readonly Writer $writer;
    private Authenticator $auth;
    private ServerInfo $serverInfo;
    private ConnectionOptions $options;

    /** @var array<string, Subscription> */
    private array $subscriptions = [];
    private int $nextSid = 1;
    private string $status = self::STATUS_DISCONNECTED;
    private int $outstandingPings = 0;
    private float $lastPingTime = 0.0;

    // Mux inbox for request-reply
    private ?Subscription $inboxSub = null;
    private string $inboxPrefix = '';
    private bool $collecting = false;

    // Reconnection
    /** @var list<string> */
    private array $serverPool = [];
    private string $currentServer = '';
    /** @var list<string> */
    private array $pendingBuffer = [];
    private int $pendingBufferBytes = 0;

    private function __construct()
    {
        $this->writer = new Writer();
    }

    /**
     * Connect to a NATS server.
     *
     * @param string|list<string>|ConnectionOptions $urlOrOptions
     */
    public static function connect(
        string|array|ConnectionOptions $urlOrOptions = 'nats://127.0.0.1:4222',
        ?ConnectionOptions $options = null,
    ): self {
        if ($urlOrOptions instanceof ConnectionOptions) {
            $options = $urlOrOptions;
        } elseif (!$options instanceof \Utopia\NATS\ConnectionOptions) {
            $options = new ConnectionOptions(servers: $urlOrOptions);
        }

        $conn = new self();
        $conn->options = $options;
        $conn->auth = $conn->resolveAuthenticator($options);
        $conn->serverPool = $conn->buildServerPool($options);
        $conn->doConnect();

        return $conn;
    }

    public function publish(string $subject, string $data = '', ?string $replyTo = null, ?Headers $headers = null): void
    {
        $this->ensureConnected();

        $this->send($this->encode($subject, $data, $replyTo, $headers));
    }

    private function encode(string $subject, string $data, ?string $replyTo, ?Headers $headers): string
    {
        $hasHeaders = $headers instanceof \Utopia\NATS\Headers && $headers->all() !== [];

        if ($hasHeaders && isset($this->serverInfo) && !$this->serverInfo->headersSupported) {
            throw new ProtocolException('Server does not support message headers');
        }

        $headerWire = $hasHeaders ? $headers->toWire() : '';
        // The header block counts against the server's max payload budget.
        $wireSize = \strlen($headerWire) + \strlen($data);
        if (isset($this->serverInfo) && $wireSize > $this->serverInfo->maxPayload) {
            throw new MaxPayloadException(
                "Payload size {$wireSize} exceeds server maximum of {$this->serverInfo->maxPayload}",
            );
        }

        return $hasHeaders
            ? $this->writer->hpub($subject, $headerWire, $data, $replyTo)
            : $this->writer->pub($subject, $data, $replyTo);

    }

    public function subscribe(string $subject, ?\Closure $callback = null, ?string $queue = null): Subscription
    {
        $this->ensureConnected();

        $sid = (string) $this->nextSid++;
        $slow = $this->options->onSlowConsumer;
        $sub = new Subscription(
            $sid,
            $subject,
            $queue,
            $callback instanceof \Closure ? fn(Message $message) => $this->notify($callback, $message) : null,
            $this->options->subPendingMsgsLimit,
            $this->options->subPendingBytesLimit,
            $slow instanceof \Closure ? fn(Subscription $subscription) => $this->notify($slow, $subscription) : null,
        );
        $sub->setConnection($this);

        $this->subscriptions[$sid] = $sub;
        $this->send($this->writer->sub($subject, $sid, $queue));

        return $sub;
    }

    public function queueSubscribe(string $subject, string $queue, ?\Closure $callback = null): Subscription
    {
        return $this->subscribe($subject, $callback, $queue);
    }

    public function unsubscribe(Subscription $sub, ?int $maxMessages = null): void
    {
        if ($maxMessages !== null) {
            $sub->setMaxMessages($sub->getReceived() + $maxMessages);
            $this->send($this->writer->unsub($sub->sid, $maxMessages));
        } else {
            $sub->setInactive();
            unset($this->subscriptions[$sub->sid]);
            $this->send($this->writer->unsub($sub->sid));
        }
    }

    public function request(string $subject, string $data = '', ?float $timeout = null, ?Headers $headers = null): Message
    {
        $request = new Request($subject, $data, $headers);
        for ($attempt = 0; ; $attempt++) {
            $result = null;
            $this->requestBatch([$request], static function (int $index, Message|\Throwable $response) use (&$result): void {
                $result = $response;
            }, $timeout);
            // Only the single-request API retries a server's explicit stale rejection.
            // Ambiguous writes and other errors are never replayed.
            if ($attempt === 0 && $result instanceof ProtocolException
                && str_contains(strtolower($result->getMessage()), 'stale connection')
                && $this->options->allowReconnect && $this->status === self::STATUS_DISCONNECTED) {
                $this->attemptReconnect();
                continue;
            }
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result ?? throw new \LogicException('Request completed without an outcome');
        }
    }

    /**
     * Send independent requests together. Deliver indexed outcomes as replies arrive.
     * One response deadline starts after writing; ambiguous writes are never replayed.
     * Callback exceptions abort collection, not already-sent remote work.
     *
     * @param list<Request> $requests
     * @param \Closure(int, Message|\Throwable): void $reply
     */
    public function requestBatch(array $requests, \Closure $reply, ?float $timeout = null): void
    {
        $this->assertReadable();
        $timeout ??= $this->options->requestTimeout;
        if (!is_finite($timeout) || $timeout <= 0 || !array_is_list($requests)) {
            throw new \InvalidArgumentException('Requests must be a list with a finite positive timeout');
        }
        foreach ($requests as $request) {
            if (!$request instanceof Request) {
                throw new \InvalidArgumentException('Expected Request objects');
            }
        }
        if ($requests === []) {
            return;
        }
        $pending = [];
        $publishing = $written = false;
        $this->collecting = true;
        try {
            try {
                $this->ensureConnected();
                $this->ensureInboxSub();
                $wire = '';
                foreach ($requests as $index => $request) {
                    $token = Inbox::generateId();
                    $pending[$token] = $index;
                    $wire .= $this->encode($request->subject, $request->data, $this->inboxPrefix . '.' . $token, $request->headers);
                }
                $publishing = true;
                $this->transport->write($wire);
                $written = true;
                $deadline = hrtime(true) / 1e9 + $timeout;
                while ($pending !== []) {
                    $remaining = $deadline - hrtime(true) / 1e9;
                    if ($remaining <= 0) {
                        throw new TimeoutException("Requests timed out after {$timeout}s");
                    }
                    $this->checkPings();
                    $message = $this->readMessage($remaining, reconnect: false);
                    $token = $message instanceof Message ? $this->extractInboxToken($message->subject) : null;
                    if ($token !== null && isset($pending[$token])) {
                        $index = $pending[$token];
                        unset($pending[$token]);
                        $response = $message->headers?->getStatus() === '503'
                            ? new NatsException('No responders for request') : $message;
                        $this->notify($reply, $index, $response);
                    }
                }
            } catch (NatsException $error) {
                if ($written && $error instanceof TimeoutException) {
                    $error = new TimeoutException("Request timed out after {$timeout}s", previous: $error);
                }
                if ($error instanceof ConnectionException && (!$written || !$error instanceof TimeoutException)) {
                    $this->recycleDeadConnection(false);
                }
                if (!$publishing) {
                    throw $error;
                }
                foreach ($pending as $index) {
                    $this->notify($reply, $index, $error);
                }
            }
        } catch (CallbackException $error) {
            throw $error->cause;
        } finally {
            $this->collecting = false;
        }
    }

    private function assertReadable(): void
    {
        if ($this->collecting) {
            throw new \LogicException('Cannot read the connection while collecting requests');
        }
    }

    /** Preserve exception provenance across protocol dispatch and application callbacks. */
    private function notify(\Closure $callback, mixed ...$arguments): void
    {
        try {
            $callback(...$arguments);
        } catch (\Throwable $error) {
            throw $this->collecting && !$error instanceof CallbackException ? new CallbackException($error) : $error;
        }
    }

    /**
     * Scatter-gather request: publish once and collect every reply until a stop
     * condition is met (ADR-47). A 503 "no responders" reply means zero
     * responders and yields an empty list.
     *
     * @param array{max?: int, timeout?: float, stall?: float} $opts
     *   max     stop after this many replies
     *   timeout overall deadline in seconds (defaults to the request timeout)
     *   stall   stop when no new reply arrives within this many seconds
     * @return list<Message>
     */
    public function requestMany(string $subject, string $data = '', array $opts = []): array
    {
        $this->assertReadable();
        $this->ensureConnected();

        $max = $opts['max'] ?? null;
        $timeout = $opts['timeout'] ?? $this->options->requestTimeout;
        $stall = $opts['stall'] ?? null;

        // Dedicated inbox subscription so replies never touch the mux inbox used
        // by the single-reply request().
        $inbox = Inbox::create($this->options->inboxPrefix);
        $sub = $this->subscribe($inbox);
        $this->publish($subject, $data, $inbox);

        $deadline = microtime(true) + $timeout;
        $messages = [];

        while ($max === null || \count($messages) < $max) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            // A stall window caps how long we wait for the next reply; whichever
            // of stall/overall-deadline is sooner bounds this iteration.
            $wait = $stall !== null ? min($remaining, $stall) : $remaining;

            $msg = $sub->nextMessage($wait);
            if (!$msg instanceof Message) {
                // Overall deadline or stall window elapsed with no new reply.
                break;
            }

            // 503 no-responders: zero responders, return whatever we have (none).
            if ($msg->headers instanceof Headers && $msg->headers->getStatus() === '503') {
                break;
            }

            $messages[] = $msg;
        }

        $this->unsubscribe($sub);

        return $messages;
    }

    public function newInbox(): string
    {
        return Inbox::create($this->options->inboxPrefix);
    }

    /**
     * Drive connection maintenance without consuming a message: send the
     * keepalive PING when it is due, and recycle the connection when the server
     * has stopped answering. A holder that keeps a connection open without
     * reading from it -- a pooled publisher, say -- must call this on an
     * interval shorter than the server's ping deadline, because nothing else
     * in this class runs on its own.
     *
     * This reads the socket, so the caller must hold the connection
     * exclusively for the call. Pool maintenance satisfies that by sweeping
     * only resources that are idle; a coroutine must never tick a connection
     * another coroutine is inside a call on.
     */
    public function tick(): void
    {
        $this->assertReadable();
        $this->checkPings();
        $this->collectPongs();
    }

    /**
     * Consume the PONGs owed for the keepalive PINGs this connection has sent.
     *
     * checkPings() only writes: it is the read path that clears
     * $outstandingPings, and a holder that never reads has none. Ticking such a
     * connection would therefore march it to maxPingsOut and declare a
     * perfectly healthy socket stale -- the keepalive would cause the outage it
     * exists to prevent. Reading here closes that loop.
     *
     * Bounded, and only while a PONG is actually owed, so maintenance can never
     * turn into a receive loop on a connection carrying subscription traffic.
     */
    private function collectPongs(): void
    {
        // readMessage() rather than processMessage(): the latter checks the
        // keepalive on entry, so draining through it would send a fresh PING
        // for every PONG collected and the outstanding count could never fall.
        for ($read = 0; $this->outstandingPings > 0 && $read < self::TICK_MAX_READS; $read++) {
            $this->readMessage(self::TICK_READ_TIMEOUT);
        }
    }

    /**
     * Read and dispatch one server message.
     */
    public function processMessage(?float $timeout = null): ?Message
    {
        $this->assertReadable();
        $this->checkPings();

        return $this->readMessage($timeout);
    }

    /**
     * The read half of {@see self::processMessage()}, without the keepalive
     * check, so maintenance can drain the socket without writing to it.
     */
    private function readMessage(?float $timeout = null, bool $reconnect = true): ?Message
    {
        try {
            [$op, $data] = $this->parser->next($timeout);
        } catch (TimeoutException $error) {
            if (!$reconnect) {
                throw $error;
            }
            return null;
        } catch (ConnectionException $e) {
            if ($reconnect && $this->options->allowReconnect && $this->status !== self::STATUS_CLOSED) {
                $this->attemptReconnect();
                return null;
            }
            throw $e;
        }

        return $this->dispatchOp($op, $data);
    }

    /**
     * Process messages in a loop.
     *
     * @param int $count Number of messages to process (0 = forever)
     */
    public function wait(int $count = 0, ?float $timeout = null): void
    {
        $processed = 0;
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while ($count === 0 || $processed < $count) {
            $remaining = $deadline !== null ? $deadline - microtime(true) : null;
            if ($remaining !== null && $remaining <= 0) {
                return;
            }

            $msg = $this->processMessage($remaining);
            if ($msg instanceof \Utopia\NATS\Message) {
                $processed++;
            }
        }
    }

    public function jetStream(?string $domain = null, ?string $apiPrefix = null): JetStream\JetStream
    {
        return new JetStream\JetStream($this, $domain, $apiPrefix);
    }

    public function flush(?float $timeout = null): void
    {
        $this->assertReadable();
        $this->ensureConnected();
        $timeout ??= $this->options->connectTimeout;

        $this->send($this->writer->ping());
        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('Flush timed out');
            }

            [$op, $data] = $this->parser->next($remaining);
            if ($op === ServerOp::Pong) {
                $this->outstandingPings = 0;
                return;
            }
            $this->dispatchOp($op, $data);
        }
    }

    public function drain(?float $timeout = null): void
    {
        $this->assertReadable();
        if ($this->status !== self::STATUS_CONNECTED) {
            return;
        }

        $this->status = self::STATUS_DRAINING;
        $timeout ??= $this->options->drainTimeout;

        // Unsub all subscriptions, then send a PING. The server processes the
        // UNSUBs and flushes any already-queued messages ahead of the PONG, so
        // receiving that PONG is a deterministic barrier: everything the server
        // had for us has arrived, and nothing new will. This replaces the old
        // pure-timeout drain.
        foreach ($this->subscriptions as $sub) {
            $this->send($this->writer->unsub($sub->sid));
        }
        $this->send($this->writer->ping());

        $deadline = microtime(true) + $timeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            try {
                [$op, $data] = $this->parser->next($remaining);
            } catch (TimeoutException|ConnectionException) {
                break;
            }

            if ($op === ServerOp::Pong) {
                break;
            }

            $this->dispatchOp($op, $data);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $previousStatus = $this->status;
        $this->status = self::STATUS_CLOSED;

        foreach ($this->subscriptions as $sub) {
            $sub->setInactive();
        }
        $this->subscriptions = [];

        if (isset($this->transport)) {
            $this->transport->close();
        }

        if ($previousStatus === self::STATUS_CONNECTED && $this->options->onClose instanceof \Closure) {
            $this->notify($this->options->onClose);
        }
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isReconnecting(): bool
    {
        return $this->status === self::STATUS_RECONNECTING;
    }

    public function getServerInfo(): ServerInfo
    {
        return $this->serverInfo;
    }

    public function getOptions(): ConnectionOptions
    {
        return $this->options;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    // --- Internal ---

    private function doConnect(): void
    {
        $this->status = self::STATUS_CONNECTING;
        $lastError = null;

        foreach ($this->serverPool as $url) {
            try {
                $this->connectToServer($url);
                $this->status = self::STATUS_CONNECTED;
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        $this->status = self::STATUS_DISCONNECTED;
        throw new ConnectionException(
            'Failed to connect to any NATS server',
            previous: $lastError,
        );
    }

    private function connectToServer(string $url): void
    {
        $parsed = $this->parseUrl($url);
        $host = $parsed['host'];
        $port = $parsed['port'];
        $scheme = $parsed['scheme'];

        // Create transport
        if ($this->options->transportFactory instanceof \Closure) {
            $this->transport = ($this->options->transportFactory)($scheme);
        } elseif ($scheme === 'ws' || $scheme === 'wss') {
            $this->transport = new WebSocketTransport($scheme === 'wss', $this->tlsOptions());
        } elseif ($scheme === 'tls' || $this->options->tls) {
            $this->transport = new TlsTransport($this->tlsOptions());
        } else {
            $this->transport = new TcpTransport();
        }

        $this->transport->connect($host, $port, $this->options->connectTimeout);
        $this->parser = new Parser($this->transport);

        // Read INFO
        [$op, $data] = $this->parser->next($this->options->connectTimeout);
        if ($op !== ServerOp::Info) {
            throw new ProtocolException("Expected INFO, got {$op->value}");
        }
        $this->serverInfo = ServerInfo::fromArray($data);

        // Merge connect_urls into server pool
        foreach ($this->serverInfo->connectUrls as $connectUrl) {
            if (!\in_array($connectUrl, $this->serverPool, true)) {
                $this->serverPool[] = $this->normalizeUrl($connectUrl);
            }
        }

        // TLS upgrade over a plaintext TCP connection: when the server requires
        // TLS, or when the caller opted in and the server advertises it is
        // available. A TlsTransport / custom transport handles its own TLS.
        if ($this->transport instanceof TcpTransport && $scheme !== 'tls') {
            $wantsUpgrade = $this->serverInfo->tlsRequired
                || ($this->options->tls && $this->serverInfo->tlsAvailable);
            if ($wantsUpgrade) {
                $this->transport->upgradeTls($this->tlsOptions());
            }
        }

        // Send CONNECT
        $connectPayload = $this->buildConnectPayload();
        $this->transport->write($this->writer->connect($connectPayload));

        // Send PING and wait for PONG to confirm connection
        $this->transport->write($this->writer->ping());

        // Read until we get PONG (skip +OK if verbose)
        $deadline = microtime(true) + $this->options->connectTimeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException('Connection handshake timed out');
            }

            [$op, $data] = $this->parser->next($remaining);

            if ($op === ServerOp::Pong) {
                break;
            }
            if ($op === ServerOp::Err) {
                $this->transport->close();
                $errMsg = \is_string($data) ? $data : 'Unknown error';
                if (stripos($errMsg, 'authorization') !== false || stripos($errMsg, 'authentication') !== false) {
                    throw new AuthenticationException("Server error: {$errMsg}");
                }
                throw new ConnectionException("Server error: {$errMsg}");
            }
            // Skip +OK
        }

        $this->lastPingTime = microtime(true);
        $this->currentServer = $url;
    }

    private function buildConnectPayload(): array
    {
        $headersSupported = $this->serverInfo->headersSupported;

        $payload = [
            'verbose' => $this->options->verbose,
            'pedantic' => $this->options->pedantic,
            'lang' => self::CLIENT_LANG,
            'version' => self::CLIENT_VERSION,
            'protocol' => 1,
            'echo' => $this->options->echo,
            // Only negotiate headers (and the header-based no_responders reply)
            // when the server advertises support for them.
            'headers' => $headersSupported,
            'no_responders' => $headersSupported,
        ];

        if ($this->options->name !== '') {
            $payload['name'] = $this->options->name;
        }

        $authFields = $this->auth->authenticate($this->serverInfo->nonce);
        $payload = array_merge($payload, $authFields);

        // Dynamic providers are resolved on every (re)connect so refreshed
        // tokens/JWTs take effect without rebuilding the connection.
        if ($this->options->tokenProvider instanceof \Closure) {
            $payload['auth_token'] = (string) ($this->options->tokenProvider)();
        }
        if ($this->options->jwtProvider instanceof \Closure) {
            $payload['jwt'] = (string) ($this->options->jwtProvider)();
        }

        return $payload;
    }

    private function dispatchOp(ServerOp $op, mixed $data): ?Message
    {
        return match ($op) {
            ServerOp::Msg, ServerOp::HMsg => $this->handleMessage($data),
            ServerOp::Ping => $this->handlePing(),
            ServerOp::Pong => $this->handlePong(),
            ServerOp::Err => $this->handleError($data),
            ServerOp::Ok => null,
            ServerOp::Info => $this->handleInfo($data),
        };
    }

    private function handleMessage(array $data): Message
    {
        $headers = null;
        if (isset($data['headers'])) {
            $headers = Headers::fromWire($data['headers']);
        }

        $msg = new Message(
            subject: $data['subject'],
            data: $data['payload'],
            replyTo: $data['replyTo'],
            headers: $headers,
            sid: $data['sid'],
        );

        // Private inbox replies belong to the active request reader, never a backlog.
        if ($this->inboxSub instanceof Subscription && $data['sid'] === $this->inboxSub->sid) {
            return $msg;
        }

        // Dispatch to subscription
        $sub = $this->subscriptions[$data['sid']] ?? null;
        if ($sub !== null && $sub->isActive()) {
            $sub->deliver($msg);

            // Clean up auto-unsubscribed subscriptions
            if (!$sub->isActive()) {
                unset($this->subscriptions[$data['sid']]);
            }
        }

        return $msg;
    }

    private function handlePing(): null
    {
        $this->send($this->writer->pong());
        return null;
    }

    private function handlePong(): null
    {
        $this->outstandingPings = max(0, $this->outstandingPings - 1);
        return null;
    }

    private function handleError(mixed $data): never
    {
        $message = \is_string($data) ? $data : 'Unknown server error';
        $error = self::mapServerError($message);

        // Closing errors must make the connection unusable even when onError throws.
        if (self::closesConnection($message)) {
            $this->recycleDeadConnection(!$this->collecting && self::reconnectsAfter($message));
        }

        if ($this->options->onError instanceof \Closure) {
            $this->notify($this->options->onError, new NatsException($message));
        }

        throw $error;
    }

    /**
     * Recycle a connection the server has closed under us: reconnect when that
     * can help, otherwise mark it dead so ensureConnected() refuses the next
     * call. A reconnect that exhausts its attempts leaves the status at
     * disconnected, and the -ERR that got us here is the more useful thing to
     * report, so its ConnectionException is deliberately swallowed.
     *
     * Marking it dead is the important half. An error we decline to reconnect
     * after is still an error the server closed the socket for, and leaving the
     * status at connected would put us back in the state this whole path
     * exists to remove: a publish that writes into a dead socket and returns
     * success.
     */
    private function recycleDeadConnection(bool $mayReconnect = true): void
    {
        if ($mayReconnect && $this->options->allowReconnect && $this->status !== self::STATUS_CLOSED) {
            try {
                $this->attemptReconnect();
            } catch (ConnectionException) {
                // Status is disconnected; handleError() still throws the -ERR.
            }

            return;
        }

        $this->status = self::STATUS_DISCONNECTED;

        if (isset($this->transport)) {
            $this->transport->close();
        }
    }

    /**
     * Whether NATS closes the connection after this -ERR. Purely a statement
     * about the socket, deliberately separate from whether reconnecting is
     * worth doing -- see {@see self::reconnectsAfter()}.
     *
     * The two were one predicate at first, which quietly recreated the bug this
     * class is fixing: an error excluded from reconnect was also excluded from
     * being recorded as closed, so an authorization failure left the status at
     * connected over a socket the server had already dropped, and the next
     * publish() wrote into it and returned success.
     *
     * Pure so the classification can be unit tested without a live connection.
     */
    public static function closesConnection(string $message): bool
    {
        $lower = strtolower($message);

        foreach (self::CLOSING_ERRORS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether rebuilding the connection can plausibly succeed after this -ERR.
     *
     * Only asked of errors that closed the connection. False for the ones a
     * retry cannot fix -- credentials the server just rejected, a TLS
     * requirement we are not meeting, a server at its connection ceiling, or
     * protocol this client got wrong -- because reconnecting there turns a hard
     * failure into a hot loop against a server that will refuse us identically
     * every time. Those connections are marked dead instead, which surfaces the
     * failure to the caller rather than hiding it behind a retry storm.
     */
    public static function reconnectsAfter(string $message): bool
    {
        $lower = strtolower($message);

        foreach (self::UNRECOVERABLE_ERRORS as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Map a server -ERR string to a typed exception (ADR-7). Pure so the mapping
     * can be unit tested without a live connection.
     */
    public static function mapServerError(string $message): NatsException
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'permissions violation') => new PermissionException($message),
            str_contains($lower, 'authorization violation'),
            str_contains($lower, 'authentication expired'),
            str_contains($lower, 'authorization'),
            str_contains($lower, 'authentication') => new AuthenticationException($message),
            str_contains($lower, 'maximum payload') => new MaxPayloadException($message),
            default => new ProtocolException("Server error: {$message}"),
        };
    }

    private function handleInfo(mixed $data): null
    {
        if (\is_array($data)) {
            $this->serverInfo = ServerInfo::fromArray($data);

            // Async INFO may advertise additional cluster members; fold any new
            // ones into the pool so failover has somewhere to go.
            foreach ($this->serverInfo->connectUrls as $connectUrl) {
                $normalized = $this->normalizeUrl($connectUrl);
                if (!\in_array($normalized, $this->serverPool, true)) {
                    $this->serverPool[] = $normalized;
                }
            }

            // Lame-duck mode (ADR-5): the server is draining and will close the
            // connection. Notify the caller and move to a different server if we
            // know of one.
            if (($data['ldm'] ?? false) === true) {
                $this->handleLameDuck();
            }
        }
        return null;
    }

    private function handleLameDuck(): void
    {
        if ($this->options->onLameDuck instanceof \Closure) {
            $this->notify($this->options->onLameDuck);
        }

        $others = array_values(array_filter(
            $this->serverPool,
            fn(string $url): bool => $url !== $this->currentServer,
        ));

        // Only proactively reconnect when a different server is available;
        // otherwise ride out the current connection until it is closed.
        if ($others !== [] && !$this->collecting && $this->options->allowReconnect) {
            $this->serverPool = [...$others, $this->currentServer];
            $this->attemptReconnect();
        }
    }

    /**
     * Emit the keepalive PING when one is due, and act on a spent budget first.
     *
     * Only called from paths that also read the socket -- {@see
     * self::processMessage()}, {@see self::requestBatch()} and {@see self::tick()} -- because a PING is only
     * half a keepalive. It is the read that clears $outstandingPings, so a
     * caller that sends without reading marches the count to maxPingsOut and
     * declares a healthy connection stale. See {@see self::ensureConnected()}
     * for the write path, which takes the verdict without the emission.
     */
    private function checkPings(): void
    {
        // Drain received operations before sending another probe or judging its reply.
        if ($this->status !== self::STATUS_CONNECTED || $this->parser->hasBufferedData()) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastPingTime) < $this->options->pingInterval) {
            return;
        }

        if ($this->checkStale()) {
            return;
        }

        try {
            $this->send($this->writer->ping());
            $this->outstandingPings++;
            $this->lastPingTime = $now;
        } catch (ConnectionException $error) {
            if ($this->collecting) {
                throw $error;
            }
            if ($this->options->allowReconnect) {
                $this->attemptReconnect();
            }
        }
    }

    /**
     * The staleness verdict on its own: the server has left this many PINGs
     * unanswered, so the connection is gone. Reconnects where that is allowed
     * and raises otherwise, and reports whether it acted so callers can stop.
     *
     * Split out of checkPings() so the write path can reach the verdict without
     * the emission that goes with it.
     */
    private function checkStale(): bool
    {
        // An owed PONG may already be buffered, including between request batches.
        if ($this->status !== self::STATUS_CONNECTED || $this->parser->hasBufferedData()) {
            return false;
        }

        if ($this->outstandingPings < $this->options->maxPingsOut) {
            return false;
        }

        if (!$this->collecting && $this->options->allowReconnect) {
            $this->attemptReconnect();

            return true;
        }

        throw new ConnectionException('Stale connection: too many outstanding pings');
    }

    private function attemptReconnect(): void
    {
        if ($this->status === self::STATUS_CLOSED || $this->status === self::STATUS_RECONNECTING) {
            return;
        }

        $this->status = self::STATUS_RECONNECTING;

        if ($this->options->onDisconnect instanceof \Closure) {
            $this->notify($this->options->onDisconnect);
        }

        if (isset($this->transport)) {
            $this->transport->close();
        }

        for ($attempt = 0; $attempt < $this->options->maxReconnectAttempts; $attempt++) {
            // Exponential backoff (capped) plus jitter before reconnecting.
            if ($attempt > 0) {
                $backoff = self::reconnectBackoff(
                    $attempt,
                    $this->options->reconnectWait,
                    $this->options->maxReconnectWait,
                );
                $wait = $backoff + (lcg_value() * $this->options->reconnectJitter);
                usleep((int) ($wait * 1_000_000));
            }

            foreach ($this->serverPool as $url) {
                try {
                    $this->connectToServer($url);
                    $this->status = self::STATUS_CONNECTED;
                    $this->outstandingPings = 0;

                    // Re-subscribe all active subscriptions
                    foreach ($this->subscriptions as $sub) {
                        if ($sub->isActive()) {
                            $this->send($this->writer->sub($sub->subject, $sub->sid, $sub->queue));
                        }
                    }

                    // Flush any buffered publishes
                    $buffered = $this->pendingBuffer;
                    $this->pendingBuffer = [];
                    $this->pendingBufferBytes = 0;
                    foreach ($buffered as $cmd) {
                        $this->send($cmd);
                    }

                    if ($this->options->onReconnect instanceof \Closure) {
                        $this->notify($this->options->onReconnect);
                    }

                    return;
                } catch (CallbackException $error) {
                    throw $error;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $this->status = self::STATUS_DISCONNECTED;
        throw new ConnectionException('Failed to reconnect to any NATS server');
    }

    private function ensureConnected(): void
    {
        if ($this->status !== self::STATUS_CONNECTED && $this->status !== self::STATUS_DRAINING) {
            throw new ConnectionException("Not connected (status: {$this->status})");
        }

        // Take the keepalive verdict before the caller writes rather than
        // after: a connection that has already outlived the server's ping
        // deadline is recycled here, so the write that follows lands on a live
        // socket or raises.
        //
        // The verdict only, never the PING. checkPings() increments
        // $outstandingPings and it is the read path that clears it, so emitting
        // here would make every write-only caller -- a pooled publisher between
        // maintenance sweeps, most of all -- accumulate one unanswered PING per
        // interval and reconnect a perfectly healthy socket on a timer. A
        // connection that is actively publishing is not idle, either: the
        // server sees its traffic, so there is nothing for a PING from here to
        // keep alive. Emission belongs to the paths that also read -- see
        // {@see self::checkPings()}.
        $this->checkStale();
    }

    private function send(string $data): void
    {
        if ($this->status === self::STATUS_RECONNECTING) {
            $this->bufferPending($data);
            return;
        }

        try {
            $this->transport->write($data);
        } catch (ConnectionException $e) {
            if (!$this->collecting && $this->options->allowReconnect && $this->status !== self::STATUS_CLOSED) {
                $this->bufferPending($data);
                $this->attemptReconnect();
                return;
            }
            $this->recycleDeadConnection(false);
            throw $e;
        }
    }

    /**
     * Buffer a command while reconnecting, enforcing the reconnect buffer cap.
     * Commands that would exceed the cap are dropped (and reported via onError)
     * rather than growing the buffer without bound.
     */
    private function bufferPending(string $data): void
    {
        if (!self::reconnectBufferAccepts($this->pendingBufferBytes, \strlen($data), $this->options->reconnectBufSize)) {
            if ($this->options->onError instanceof \Closure) {
                $this->notify($this->options->onError, new NatsException('Reconnect buffer full; dropping pending message'));
            }
            return;
        }

        $this->pendingBuffer[] = $data;
        $this->pendingBufferBytes += \strlen($data);
    }

    /**
     * Exponential reconnect backoff (in seconds), capped. Attempt 0 waits 0s
     * (the first reconnect is immediate); subsequent attempts grow by $factor.
     */
    public static function reconnectBackoff(int $attempt, float $base, float $cap, float $factor = 2.0): float
    {
        if ($attempt <= 0) {
            return 0.0;
        }

        $delay = $base * ($factor ** ($attempt - 1));

        return min($delay, $cap);
    }

    /**
     * Whether $incomingBytes may be appended to the reconnect buffer without
     * exceeding $cap. A non-positive cap disables buffering entirely.
     */
    public static function reconnectBufferAccepts(int $currentBytes, int $incomingBytes, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }

        return ($currentBytes + $incomingBytes) <= $cap;
    }

    private function ensureInboxSub(): void
    {
        if ($this->inboxSub instanceof \Utopia\NATS\Subscription) {
            return;
        }

        $this->inboxPrefix = $this->options->inboxPrefix . '.' . Inbox::generateId();
        $this->inboxSub = $this->subscribe($this->inboxPrefix . '.*');
    }

    private function extractInboxToken(string $subject): ?string
    {
        if (!str_starts_with($subject, $this->inboxPrefix . '.')) {
            return null;
        }

        return substr($subject, \strlen($this->inboxPrefix) + 1);
    }

    private function resolveAuthenticator(ConnectionOptions $options): Authenticator
    {
        if ($options->credentialsFile !== null) {
            return new CredentialsAuth($options->credentialsFile);
        }

        if ($options->nkey !== null && $options->nkeySeed !== null) {
            return new NKeyAuth($options->nkey, $options->nkeySeed);
        }

        if ($options->token !== null) {
            return new TokenAuth($options->token);
        }

        if ($options->user !== null && $options->pass !== null) {
            return new UserPassAuth($options->user, $options->pass);
        }

        // Check URL for user info
        foreach ($options->servers as $url) {
            $parsed = parse_url($url);
            if (isset($parsed['user'])) {
                $user = rawurldecode($parsed['user']);
                $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : '';

                if ($pass !== '') {
                    return new UserPassAuth($user, $pass);
                }
                return new TokenAuth($user);
            }
        }

        return new NoAuth();
    }

    /**
     * @return list<string>
     */
    private function buildServerPool(ConnectionOptions $options): array
    {
        $servers = array_map($this->normalizeUrl(...), $options->servers);

        if (!$options->noRandomize && \count($servers) > 1) {
            shuffle($servers);
        }

        return $servers;
    }

    /**
     * TLS context options shared by the initial TLS connect and STARTTLS upgrade.
     *
     * @return array<string, mixed>
     */
    private function tlsOptions(): array
    {
        $opts = [
            'cafile' => $this->options->tlsCaFile,
            'local_cert' => $this->options->tlsCertFile,
            'local_pk' => $this->options->tlsKeyFile,
            'verify_peer' => $this->options->tlsVerify,
            'verify_peer_name' => $this->options->tlsVerify,
        ];

        if ($this->options->tlsServerName !== null) {
            $opts['peer_name'] = $this->options->tlsServerName;
        }

        return $opts;
    }

    private function normalizeUrl(string $url): string
    {
        if (!preg_match('#^(nats|tls|ws|wss)://#', $url)) {
            return 'nats://' . $url;
        }
        return $url;
    }

    /**
     * @return array{scheme: string, host: string, port: int}
     */
    private function parseUrl(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new ConnectionException("Invalid server URL: {$url}");
        }

        return [
            'scheme' => $parsed['scheme'] ?? 'nats',
            'host' => $parsed['host'] ?? '127.0.0.1',
            'port' => $parsed['port'] ?? 4222,
        ];
    }
}
