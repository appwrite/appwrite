<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class ConnectionOptions
{
    /** @var list<string> */
    public readonly array $servers;

    /**
     * @param string|list<string> $servers
     */
    public function __construct(
        string|array $servers = 'nats://127.0.0.1:4222',
        public readonly string $name = '',
        // Auth
        public readonly ?string $user = null,
        public readonly ?string $pass = null,
        public readonly ?string $token = null,
        public readonly ?string $nkey = null,
        public readonly ?string $nkeySeed = null,
        public readonly ?string $credentialsFile = null,
        // TLS
        public readonly bool $tls = false,
        public readonly ?string $tlsCaFile = null,
        public readonly ?string $tlsCertFile = null,
        public readonly ?string $tlsKeyFile = null,
        public readonly bool $tlsVerify = true,
        public readonly ?string $tlsServerName = null,
        // Dynamic auth providers, resolved on every (re)connect so tokens can refresh.
        public readonly ?\Closure $tokenProvider = null,
        public readonly ?\Closure $jwtProvider = null,
        // Reconnection
        public readonly bool $allowReconnect = true,
        public readonly int $maxReconnectAttempts = 60,
        public readonly float $reconnectWait = 2.0,
        public readonly float $maxReconnectWait = 8.0,
        public readonly float $reconnectJitter = 0.1,
        // Max bytes buffered for pending publishes while reconnecting; excess is dropped.
        public readonly int $reconnectBufSize = 8_388_608,
        // Slow-consumer limits per subscription (pending messages / bytes).
        public readonly int $subPendingMsgsLimit = 65536,
        public readonly int $subPendingBytesLimit = 67_108_864,
        // Timeouts
        public readonly float $connectTimeout = 2.0,
        public readonly float $requestTimeout = 5.0,
        public readonly float $drainTimeout = 30.0,
        // PING/PONG
        public readonly float $pingInterval = 120.0,
        public readonly int $maxPingsOut = 2,
        // Misc
        public readonly bool $verbose = false,
        public readonly bool $pedantic = false,
        public readonly bool $echo = true,
        public readonly bool $noRandomize = false,
        public readonly string $inboxPrefix = '_INBOX',
        // Callbacks
        public readonly ?\Closure $onDisconnect = null,
        public readonly ?\Closure $onReconnect = null,
        public readonly ?\Closure $onClose = null,
        public readonly ?\Closure $onError = null,
        // Fired with the Subscription when it exceeds its pending limits.
        public readonly ?\Closure $onSlowConsumer = null,
        // Fired when the server signals lame-duck mode (async INFO with "ldm": true).
        public readonly ?\Closure $onLameDuck = null,
        // Transport: fn(string $scheme): Transport. Defaults to the stream-based
        // Tcp/Tls transports; inject to use a coroutine-native transport (e.g. Swoole).
        public readonly ?\Closure $transportFactory = null,
    ) {
        $this->servers = \is_string($servers) ? [$servers] : $servers;
    }
}
