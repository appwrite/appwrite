<?php

namespace Appwrite\Push;

use Swoole\Server;
use Swoole\Table;
use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Messaging\Helpers\MQTT;

/**
 * Appwrite Push MQTT 5 broker.
 *
 * Single-process Swoole TCP server tuned for high device-fan-out with low
 * per-connection overhead. Devices connect once on app start with a 30-minute
 * keep-alive and hold the socket open. The server publishes by writing to a
 * device-specific topic and the broker forwards in O(1).
 *
 * Trade-offs:
 *   - In-process state, single broker per deployment. Horizontal scaling needs
 *     Redis pub/sub for cross-instance fan-out (separate work item).
 *   - Retained-on-disconnect messages live in a Swoole\Table sized by
 *     _APP_PUSH_RETENTION_MAX (default 4096 pending messages).
 *   - QoS 1 is the only delivery class supported. QoS 0 publishes are upgraded
 *     transparently; QoS 2 is rejected with a malformed-packet reason code.
 */
final class Broker
{
    private const SESSION_TABLE_SIZE = 16384;
    private const TOPIC_TABLE_SIZE = 16384;
    private const PENDING_TABLE_SIZE = 8192;
    private const SOCKET_BUFFER_KEY = 'buf';

    private Server $server;

    /** Maps fd → device clientId. */
    private Table $sessions;

    /** Maps clientId → fd (for inbound routing). */
    private Table $clients;

    /** Maps fd → buffered bytes awaiting full packet boundary. */
    private Table $buffers;

    /** Maps clientId+messageId → pending payload (for offline devices). */
    private Table $pending;

    /** Last activity timestamp per fd, used to enforce keep-alive. */
    private Table $heartbeat;

    public function __construct(
        private readonly Token $tokens,
        private readonly int $port = 8883,
        private readonly bool $tls = true,
        private readonly string $tlsCertificate = '',
        private readonly string $tlsKey = '',
        private readonly int $maxKeepAlive = 1800,
        private readonly int $retentionSeconds = 86400,
    ) {
        $this->initialiseTables();
    }

    public function start(): void
    {
        $mode = $this->tls ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
        $this->server = new Server('0.0.0.0', $this->port, SWOOLE_BASE, $mode);

        $config = [
            'worker_num' => (int)\max(1, \swoole_cpu_num()),
            'max_request' => 0,
            'open_tcp_nodelay' => true,
            'tcp_keepalive' => 1,
            'reactor_num' => (int)\max(1, \swoole_cpu_num()),
            'open_eof_check' => false,
            'log_level' => SWOOLE_LOG_INFO,
        ];

        if ($this->tls) {
            if ($this->tlsCertificate === '' || $this->tlsKey === '') {
                throw new \RuntimeException('TLS enabled but _APP_PUSH_TLS_CERT/_APP_PUSH_TLS_KEY are missing.');
            }
            $config['ssl_cert_file'] = $this->tlsCertificate;
            $config['ssl_key_file'] = $this->tlsKey;
            $config['ssl_protocols'] = SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3;
        }

        $this->server->set($config);

        $this->server->on('start', $this->onStart(...));
        $this->server->on('workerStart', $this->onWorkerStart(...));
        $this->server->on('connect', $this->onConnect(...));
        $this->server->on('receive', $this->onReceive(...));
        $this->server->on('close', $this->onClose(...));

        $this->server->start();
    }

    private function initialiseTables(): void
    {
        $this->sessions = new Table(self::SESSION_TABLE_SIZE);
        $this->sessions->column('clientId', Table::TYPE_STRING, 96);
        $this->sessions->column('topic', Table::TYPE_STRING, 128);
        $this->sessions->column('scope', Table::TYPE_STRING, 16);
        $this->sessions->column('uid', Table::TYPE_STRING, 64);
        $this->sessions->column('pid', Table::TYPE_STRING, 64);
        $this->sessions->column('keepAlive', Table::TYPE_INT);
        $this->sessions->column('exp', Table::TYPE_INT);
        $this->sessions->create();

        $this->clients = new Table(self::TOPIC_TABLE_SIZE);
        $this->clients->column('fd', Table::TYPE_INT);
        $this->clients->create();

        $this->buffers = new Table(self::SESSION_TABLE_SIZE);
        $this->buffers->column(self::SOCKET_BUFFER_KEY, Table::TYPE_STRING, 65535);
        $this->buffers->create();

        $this->pending = new Table(self::PENDING_TABLE_SIZE);
        $this->pending->column('topic', Table::TYPE_STRING, 128);
        $this->pending->column('payload', Table::TYPE_STRING, 8192);
        $this->pending->column('expiresAt', Table::TYPE_INT);
        $this->pending->create();

        $this->heartbeat = new Table(self::SESSION_TABLE_SIZE);
        $this->heartbeat->column('lastSeen', Table::TYPE_INT);
        $this->heartbeat->create();
    }

    private function onStart(Server $server): void
    {
        Console::success("Appwrite Push broker listening on port {$this->port} (TLS=" . ($this->tls ? 'on' : 'off') . ')');
    }

    private function onWorkerStart(Server $server, int $workerId): void
    {
        if ($workerId !== 0) {
            return;
        }

        Timer::tick(15000, function () use ($server) {
            $now = \time();
            foreach ($this->heartbeat as $fd => $row) {
                $session = $this->sessions->get((string)$fd);
                if (!$session) {
                    $this->heartbeat->del((string)$fd);
                    continue;
                }

                $deadline = (int)$row['lastSeen'] + (int)\max(60, $session['keepAlive'] * 2);
                if ($now > $deadline) {
                    Console::warning("Closing fd {$fd} (idle)");
                    $server->close((int)$fd);
                }
            }

            foreach ($this->pending as $key => $row) {
                if ((int)$row['expiresAt'] < $now) {
                    $this->pending->del($key);
                }
            }
        });
    }

    private function onConnect(Server $server, int $fd): void
    {
        $this->buffers->set((string)$fd, [self::SOCKET_BUFFER_KEY => '']);
        $this->heartbeat->set((string)$fd, ['lastSeen' => \time()]);
    }

    private function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $row = $this->buffers->get((string)$fd);
        $buffer = ($row[self::SOCKET_BUFFER_KEY] ?? '') . $data;

        try {
            while (($packet = MQTT::decodePacket($buffer)) !== null) {
                $this->heartbeat->set((string)$fd, ['lastSeen' => \time()]);
                $this->handlePacket($server, $fd, $packet);
            }
        } catch (\Throwable $error) {
            Console::warning("Closing fd {$fd}: {$error->getMessage()}");
            $server->send($fd, MQTT::encodeDisconnect(MQTT::REASON_MALFORMED));
            $server->close($fd);
            return;
        }

        $this->buffers->set((string)$fd, [self::SOCKET_BUFFER_KEY => $buffer]);
    }

    private function onClose(Server $server, int $fd): void
    {
        $session = $this->sessions->get((string)$fd);
        if ($session) {
            $existing = $this->clients->get($session['clientId']);
            if ($existing && (int)$existing['fd'] === $fd) {
                $this->clients->del($session['clientId']);
            }
            $this->sessions->del((string)$fd);
        }
        $this->buffers->del((string)$fd);
        $this->heartbeat->del((string)$fd);
    }

    /**
     * @param array{type: int, flags: int, payload: string} $packet
     */
    private function handlePacket(Server $server, int $fd, array $packet): void
    {
        switch ($packet['type']) {
            case MQTT::PACKET_CONNECT:
                $this->handleConnect($server, $fd, $packet['payload']);
                return;

            case MQTT::PACKET_PUBLISH:
                $this->handlePublish($server, $fd, $packet);
                return;

            case MQTT::PACKET_SUBSCRIBE:
                $this->handleSubscribe($server, $fd, $packet['payload']);
                return;

            case MQTT::PACKET_PINGREQ:
                $server->send($fd, MQTT::encodePingresp());
                return;

            case MQTT::PACKET_DISCONNECT:
                $server->close($fd);
                return;

            case MQTT::PACKET_PUBACK:
                return; // ignore — devices don't fan-out to us

            default:
                $server->send($fd, MQTT::encodeDisconnect(MQTT::REASON_PROTOCOL_ERROR));
                $server->close($fd);
        }
    }

    private function handleConnect(Server $server, int $fd, string $payload): void
    {
        $connect = MQTT::parseConnect($payload);

        if ($connect['protocol'] !== MQTT::PROTOCOL_NAME || $connect['version'] !== MQTT::PROTOCOL_VERSION) {
            $server->send($fd, MQTT::encodeConnack(MQTT::REASON_PROTOCOL_ERROR));
            $server->close($fd);
            return;
        }

        $token = (string)$connect['password'];
        if ($token === '') {
            $server->send($fd, MQTT::encodeConnack(MQTT::REASON_BAD_AUTH));
            $server->close($fd);
            return;
        }

        $claims = $this->tokens->verify($token);
        if ($claims === null) {
            $server->send($fd, MQTT::encodeConnack(MQTT::REASON_BAD_AUTH));
            $server->close($fd);
            return;
        }

        $scope = (string)($claims['scope'] ?? '');
        if (!\in_array($scope, [Token::SCOPE_DEVICE, Token::SCOPE_SERVER], true)) {
            $server->send($fd, MQTT::encodeConnack(MQTT::REASON_NOT_AUTHORIZED));
            $server->close($fd);
            return;
        }

        $keepAlive = (int)\min($this->maxKeepAlive, \max(15, $connect['keepAlive']));

        $this->sessions->set((string)$fd, [
            'clientId' => $connect['clientId'],
            'topic' => (string)($claims['topic'] ?? ''),
            'scope' => $scope,
            'uid' => (string)($claims['uid'] ?? ''),
            'pid' => (string)($claims['pid'] ?? ''),
            'keepAlive' => $keepAlive,
            'exp' => (int)($claims['exp'] ?? 0),
        ]);

        if ($scope === Token::SCOPE_DEVICE) {
            $this->clients->set($connect['clientId'], ['fd' => $fd]);
        }

        $server->send($fd, MQTT::encodeConnack(
            MQTT::REASON_SUCCESS,
            sessionPresent: false,
            properties: [
                'serverKeepAlive' => $keepAlive,
                'maximumQoS' => 1,
                'retainAvailable' => 0,
                'wildcardSubscriptionAvailable' => 0,
                'sharedSubscriptionAvailable' => 0,
                'receiveMaximum' => 1024,
            ],
        ));

        if ($scope === Token::SCOPE_DEVICE) {
            $this->flushPendingFor((string)($claims['topic'] ?? ''), $server, $fd);
        }
    }

    private function handleSubscribe(Server $server, int $fd, string $payload): void
    {
        $session = $this->sessions->get((string)$fd);
        if (!$session) {
            $server->close($fd);
            return;
        }

        $sub = MQTT::parseSubscribe($payload);
        $reasonCodes = [];

        foreach ($sub['filters'] as $filter) {
            if ($session['scope'] === Token::SCOPE_DEVICE) {
                $reasonCodes[] = ($filter['topic'] === $session['topic'])
                    ? MQTT::REASON_SUCCESS
                    : MQTT::REASON_NOT_AUTHORIZED;
            } else {
                $reasonCodes[] = MQTT::REASON_NOT_AUTHORIZED; // servers publish, never subscribe
            }
        }

        $server->send($fd, MQTT::encodeSuback($sub['packetId'], $reasonCodes));
    }

    /**
     * @param array{type: int, flags: int, payload: string} $packet
     */
    private function handlePublish(Server $server, int $fd, array $packet): void
    {
        $session = $this->sessions->get((string)$fd);
        if (!$session) {
            $server->close($fd);
            return;
        }

        $publish = MQTT::parsePublish($packet['payload'], $packet['flags']);

        if ($session['scope'] !== Token::SCOPE_SERVER) {
            if ($publish['qos'] === 1 && $publish['packetId'] !== null) {
                $server->send($fd, MQTT::encodePuback($publish['packetId'], MQTT::REASON_NOT_AUTHORIZED));
            }
            return;
        }

        if (!\str_starts_with($publish['topic'], 'appwrite/push/')) {
            if ($publish['qos'] === 1 && $publish['packetId'] !== null) {
                $server->send($fd, MQTT::encodePuback($publish['packetId'], MQTT::REASON_TOPIC_INVALID));
            }
            return;
        }

        $deviceId = \substr($publish['topic'], \strlen('appwrite/push/'));
        $target = $this->clients->get($deviceId);

        if ($target) {
            $forward = MQTT::encodePublish(
                topic: $publish['topic'],
                payload: $publish['payload'],
                qos: 1,
                retain: false,
                dup: false,
                packetId: $this->nextPacketId(),
                properties: $publish['properties'],
            );

            $server->send((int)$target['fd'], $forward);

            if ($publish['qos'] === 1 && $publish['packetId'] !== null) {
                $server->send($fd, MQTT::encodePuback($publish['packetId'], MQTT::REASON_SUCCESS));
            }
            return;
        }

        $expiry = (int)($publish['properties']['messageExpiryInterval'] ?? $this->retentionSeconds);
        $this->retain($deviceId, $publish['topic'], $publish['payload'], $expiry);

        if ($publish['qos'] === 1 && $publish['packetId'] !== null) {
            $server->send($fd, MQTT::encodePuback($publish['packetId'], 0x10));
        }
    }

    private function retain(string $deviceId, string $topic, string $payload, int $expirySeconds): void
    {
        if ($this->pending->count() >= self::PENDING_TABLE_SIZE) {
            return;
        }

        $key = $deviceId . ':' . \uniqid('', true);
        $this->pending->set($key, [
            'topic' => $topic,
            'payload' => $payload,
            'expiresAt' => \time() + \max(1, $expirySeconds),
        ]);
    }

    private function flushPendingFor(string $topic, Server $server, int $fd): void
    {
        $now = \time();
        foreach ($this->pending as $key => $row) {
            if ((int)$row['expiresAt'] < $now) {
                $this->pending->del($key);
                continue;
            }

            if ($row['topic'] !== $topic) {
                continue;
            }

            $packet = MQTT::encodePublish(
                topic: $row['topic'],
                payload: $row['payload'],
                qos: 1,
                retain: false,
                dup: true,
                packetId: $this->nextPacketId(),
            );

            $server->send($fd, $packet);
            $this->pending->del($key);
        }
    }

    private function nextPacketId(): int
    {
        static $counter = 0;
        $counter = ($counter + 1) & 0xFFFF;
        if ($counter === 0) {
            $counter = 1;
        }

        return $counter;
    }
}
