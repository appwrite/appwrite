<?php

namespace Tests\Compat\Nats;

use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Transport\Transport;

/**
 * The in-memory transport of the PHP tests (packages/nats/tests/Support/
 * FakeTransport.php): serves an INFO line on connect, answers every PING
 * with a PONG, records what is written, and runs scripted rules on each
 * write (the closures the PHP tests set as `onWrite`, as data). The twin
 * of `Fake` in crates/tools/compat/src/libs/nats.rs.
 *
 * A rule is {"when": {"prefix"|"equals"|"contains": text}, "do": [actions]};
 * actions: {"push": bytes}, {"throw": {"class", "message"}},
 * {"sleep_ms": n}, {"reply": {"subject", "payload", "reverse"?, "skip"?,
 * "status"?}} (one reply per PUB/HPUB to `subject`, or to a subject under
 * it when it ends with "."; "$suffix" as payload is the part after it),
 * {"capture": subject} (keep the reply subjects of PUBs to it) and
 * {"reply_captured": payload}.
 */
final class Fake implements Transport
{
    public string $written = '';

    /** @var list<string> */
    public array $writes = [];

    /** @var list<array<string, mixed>> */
    public array $tlsUpgrades = [];

    public bool $answerPings = true;

    /** @var list<array<string, mixed>> */
    public array $rules = [];

    /** @var array{class: string, message: string}|null */
    public ?array $failReads = null;

    public int $connects = 0;

    private string $inbound = '';

    private bool $connected = false;

    /** @var list<string> */
    private array $captured = [];

    /** @param array<string, mixed> $info */
    public function __construct(private readonly array $info = [])
    {
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->connected = true;
        $this->connects++;
        $this->inbound .= 'INFO ' . json_encode(array_merge([
            'server_id' => 'FAKE',
            'server_name' => 'fake',
            'version' => '2.10.0',
            'proto' => 1,
            'host' => '127.0.0.1',
            'port' => 4222,
            'headers' => true,
            'auth_required' => false,
            'tls_required' => false,
            'tls_available' => false,
            'max_payload' => 1048576,
            'jetstream' => true,
        ], $this->info), JSON_THROW_ON_ERROR) . "\r\n";
    }

    public function write(string $data): int
    {
        $this->written .= $data;
        $this->writes[] = $data;
        foreach ($this->rules as $rule) {
            if (self::matches($rule['when'] ?? [], $data)) {
                foreach ($rule['do'] ?? [] as $action) {
                    $this->act($action, $data);
                }
            }
        }
        if ($this->answerPings) {
            $this->inbound .= str_repeat("PONG\r\n", substr_count($data, "PING\r\n"));
        }

        return \strlen($data);
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        if ($this->failReads !== null) {
            throw Errors::make($this->failReads);
        }
        if ($this->inbound === '') {
            throw new TimeoutException('No inbound data');
        }
        $chunk = substr($this->inbound, 0, $maxBytes);
        $this->inbound = substr($this->inbound, \strlen($chunk));

        return $chunk;
    }

    public function readLine(?float $timeout = null): string
    {
        $pos = strpos($this->inbound, "\n");
        if ($pos === false) {
            throw new TimeoutException('No inbound line');
        }
        $line = substr($this->inbound, 0, $pos + 1);
        $this->inbound = substr($this->inbound, $pos + 1);

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        $this->tlsUpgrades[] = $options;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function push(string $data): void
    {
        $this->inbound .= $data;
    }

    /** @param array<string, string> $when */
    private static function matches(array $when, string $data): bool
    {
        return match (true) {
            isset($when['prefix']) => str_starts_with($data, $when['prefix']),
            isset($when['equals']) => $data === $when['equals'],
            isset($when['contains']) => str_contains($data, $when['contains']),
            default => true,
        };
    }

    /** @param array<string, mixed> $action */
    private function act(array $action, string $data): void
    {
        if (isset($action['push'])) {
            $this->inbound .= $action['push'];
        } elseif (isset($action['throw'])) {
            throw Errors::make($action['throw']);
        } elseif (isset($action['sleep_ms'])) {
            usleep((int) $action['sleep_ms'] * 1000);
        } elseif (isset($action['reply'])) {
            $reply = $action['reply'];
            $publishes = self::publishes($data, $reply['subject']);
            if ($reply['reverse'] ?? false) {
                $publishes = array_reverse($publishes);
            }
            foreach ($publishes as [$subject, $replyTo]) {
                $suffix = substr($subject, \strlen($reply['subject']));
                if (\in_array($suffix, $reply['skip'] ?? [], true)) {
                    continue;
                }
                $payload = $reply['payload'] === '$suffix' ? $suffix : $reply['payload'];
                if (isset($reply['status'])) {
                    $block = "NATS/1.0 {$reply['status']}\r\n\r\n";
                    $length = \strlen($block);
                    $this->inbound .= "HMSG {$replyTo} 1 {$length} {$length}\r\n{$block}\r\n";
                } else {
                    $this->inbound .= "MSG {$replyTo} 1 " . \strlen($payload) . "\r\n{$payload}\r\n";
                }
            }
        } elseif (isset($action['capture'])) {
            $this->captured = array_column(self::publishes($data, $action['capture']), 1);
        } elseif (isset($action['reply_captured'])) {
            foreach ($this->captured as $replyTo) {
                $payload = $action['reply_captured'];
                $this->inbound .= "MSG {$replyTo} 1 " . \strlen($payload) . "\r\n{$payload}\r\n";
            }
        }
    }

    /**
     * The [subject, reply] of each PUB/HPUB with a reply subject in $data
     * whose subject is $subject (or under it, when it ends with ".").
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function publishes(string $data, string $subject): array
    {
        $found = [];
        $offset = 0;
        while ($offset < \strlen($data)) {
            $end = strpos($data, "\r\n", $offset);
            if ($end === false) {
                break;
            }
            $parts = explode(' ', substr($data, $offset, $end - $offset));
            $offset = $end + 2;
            $size = 0;
            if ($parts[0] === 'PUB' && \count($parts) === 4) {
                $size = (int) $parts[3];
            } elseif ($parts[0] === 'HPUB' && \count($parts) === 5) {
                $size = (int) $parts[4];
            } elseif ($parts[0] === 'PUB' || $parts[0] === 'HPUB') {
                $size = (int) end($parts);
                $offset += $size + 2;
                continue;
            } else {
                continue;
            }
            $offset += $size + 2;
            $target = $parts[1];
            if ($target === $subject || (str_ends_with($subject, '.') && str_starts_with($target, $subject))) {
                $found[] = [$target, $parts[2]];
            }
        }

        return $found;
    }
}
