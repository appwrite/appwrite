<?php

namespace Tests\Compat\Smtp;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Auth\Authenticator;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Auth\XOAuth2;
use Utopia\SMTP\Capabilities;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Exception\AuthenticationException;
use Utopia\SMTP\Exception\CapabilityException;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\MessageException;
use Utopia\SMTP\Exception\ProtocolException;
use Utopia\SMTP\Exception\SmtpException;
use Utopia\SMTP\Exception\TimeoutException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Message;
use Utopia\SMTP\Mime\Encoding;
use Utopia\SMTP\Mime\Header;
use Utopia\SMTP\Mime\Part;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Reply;
use Utopia\SMTP\Result;
use Utopia\SMTP\Timeouts;
use Utopia\SMTP\Tls;
use Utopia\SMTP\Transport\Native;
use Utopia\SMTP\Verification;

require_once __DIR__ . '/Recording.php';

/**
 * Maps tests/compat/smtp/spec.json operations onto utopia-php/smtp. Glue only: no logic.
 *
 * Scripted sessions run on the library's own FakeTransport (through
 * Recording); real ones on Native against the throwaway Mailpit servers of
 * services.sh, read back through Mailpit's API. Random MIME boundaries are
 * renamed `=_BOUNDARY<n>` in order of appearance on both sides, the only
 * rewrite of a rendered message.
 */
final class Adapter implements Base, Benchmarks
{
    /** Keywords whose has()/params() a capabilities result reports by default. */
    private const KEYWORDS = ['PIPELINING', 'SIZE', '8BITMIME', 'SMTPUTF8', 'STARTTLS', 'AUTH', 'ENHANCEDSTATUSCODES', 'CHUNKING'];

    public function benchmarks(): array
    {
        $attachment = str_repeat("\x00\x01\x02binary\xff", 400);
        $ehlo = ['mail.example.test', 'PIPELINING', 'SIZE 35882577', 'ETRN', 'AUTH PLAIN LOGIN XOAUTH2', 'ENHANCEDSTATUSCODES', '8BITMIME', 'DSN', 'SMTPUTF8', 'CHUNKING'];

        return [
            'message' => ['Build and render a message with text, HTML and a 4 KiB attachment', fn (Session $s) => fn () => (string) new Message(
                from: new Address('jane@example.test', 'Jane Doe'),
                to: [new Address('john@example.test', 'John')],
                subject: 'Quarterly report',
                text: str_repeat("The numbers are in.\n", 20),
                html: str_repeat('<p>The numbers are in.</p>', 20),
                attachments: [Attachment::fromString($attachment, 'report.bin')],
                messageId: 'bench@example.test',
            )],
            'header' => ['Encode and fold a long non-ASCII subject', fn (Session $s) => fn () => Header::line('Subject', 'Rapport trimestriel très détaillé sur les résultats du troisième trimestre')],
            'ehlo' => ['Read a ten-line EHLO reply into capabilities', fn (Session $s) => fn () => Capabilities::fromReply(new Reply(250, $ehlo))->maxSize()],
            'send' => ['Send a message to Mailpit over an open session', function (Session $s) {
                $client = new Client(self::native($s, ['service' => 'smtp']), 'bench.example.test', [new Plain('jane', 'secret')], Encryption::None);
                $message = new Message(new Address('jane@example.test'), [new Address('john@example.test')], 'Bench', 'Body', messageId: 'bench@example.test');

                return fn () => $client->send($message);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'address.new' => fn (array $a, Session $s) => self::addressOut(self::address($a)),

            'header.line' => fn (array $a, Session $s) => Header::line($a['name'], $a['value']),
            'header.phrase' => fn (array $a, Session $s) => Header::phrase($a['value']),

            'encoding.is_ascii' => fn (array $a, Session $s) => Encoding::isAscii($a['value']),
            'encoding.quoted_printable' => fn (array $a, Session $s) => Encoding::quotedPrintable($a['value']),
            'encoding.base64' => fn (array $a, Session $s) => Encoding::base64($a['value']),
            'encoding.line_endings' => fn (array $a, Session $s) => Encoding::lineEndings($a['value']),
            'encoding.boundary' => function (array $a, Session $s) {
                $boundaries = [];
                for ($i = 0; $i < $a['count']; ++$i) {
                    $boundaries[] = Encoding::boundary();
                }

                return [
                    'distinct' => \count(array_unique($boundaries)),
                    'lengths' => array_values(array_unique(array_map(strlen(...), $boundaries))),
                    'prefixes' => array_values(array_unique(array_map(static fn (string $b) => substr($b, 0, 2), $boundaries))),
                    'hex' => array_values(array_unique(array_map(static fn (string $b) => ctype_xdigit(substr($b, 2)), $boundaries))),
                ];
            },

            'envelope.new' => fn (array $a, Session $s) => self::envelopeOut(self::envelope($a)),
            'envelope.from_message' => fn (array $a, Session $s) => self::envelopeOut(Envelope::fromMessage(self::message($a['message']))),

            'attachment.new' => function (array $a, Session $s) {
                $attachment = self::attachment($a);

                return [
                    'name' => $attachment->name,
                    'type' => $attachment->type,
                    'content' => $attachment->content,
                    'path' => $attachment->path,
                    'cid' => $attachment->cid,
                ];
            },

            'message.render' => function (array $a, Session $s) {
                $message = self::message($a['message']);
                $string = (string) $message;
                $names = self::boundaries($string);

                return [
                    'string' => strtr($string, $names),
                    'chunks' => array_map(static fn (string $c) => strtr($c, $names), iterator_to_array($message->toIterable(), false)),
                    'international' => $message->isInternational(),
                    'message_id' => $message->messageId,
                    'date' => $message->date->format(\DateTimeInterface::RFC2822),
                ];
            },

            'part.render' => function (array $a, Session $s) {
                $part = self::part($a['part']);
                $head = $part->head();
                $body = iterator_to_array($part->body(), false);
                $names = self::boundaries($head . implode('', $body));

                return ['head' => strtr($head, $names), 'body' => array_map(static fn (string $c) => strtr($c, $names), $body)];
            },

            'reply.new' => fn (array $a, Session $s) => self::replyOut(new Reply($a['code'], $a['lines'])),
            'outcome.from_code' => fn (array $a, Session $s) => Outcome::fromCode($a['code'])->name,

            'capabilities.parse' => fn (array $a, Session $s) => self::capabilitiesOut(
                Capabilities::fromReply(new Reply($a['code'] ?? 250, $a['lines'])),
                $a['keywords'] ?? self::KEYWORDS,
            ),
            'capabilities.none' => fn (array $a, Session $s) => self::capabilitiesOut(Capabilities::none(), $a['keywords'] ?? self::KEYWORDS),

            'timeouts.new' => function (array $a, Session $s) {
                $timeouts = new Timeouts(...$a);

                return ['connect' => $timeouts->connect, 'read' => $timeouts->read, 'write' => $timeouts->write];
            },
            'tls.new' => function (array $a, Session $s) {
                $tls = self::tls($a);

                return ['verify' => $tls->verify->name, 'peer_name' => $tls->peerName, 'ca_file' => $tls->caFile, 'ciphers' => $tls->ciphers];
            },

            'auth.exchange' => function (array $a, Session $s) {
                $authenticator = self::authenticator($a);
                $responses = [];
                foreach ($a['challenges'] ?? [] as $step => $challenge) {
                    $responses[] = $authenticator->respond($challenge, $step);
                }

                return ['mechanism' => $authenticator->mechanism(), 'initial' => $authenticator->initial(), 'responses' => $responses];
            },

            'exception.new' => function (array $a, Session $s) {
                $exception = self::exception($a);

                return self::errorOut($exception) + [
                    'smtp' => $exception instanceof SmtpException,
                    'connection' => $exception instanceof ConnectionException,
                ];
            },

            'file.write' => function (array $a, Session $s) {
                @mkdir(\dirname($a['path']), 0777, true);
                file_put_contents($a['path'], $a['content']);

                return;
            },
            'file.delete' => fn (array $a, Session $s) => @unlink($a['path']),

            'client.new' => fn (array $a, Session $s) => self::client($a, $s),
            'client.send_raw' => fn (array $a, Session $s) => self::catching($a, fn () => self::resultOut(self::clientOf($a, $s)->sendRaw(
                self::envelope($a['envelope']),
                $a['content'],
            ))),
            'client.send' => fn (array $a, Session $s) => self::catching($a, fn () => self::resultOut(self::clientOf($a, $s)->send(self::message($a['message'])))),
            'client.capabilities' => fn (array $a, Session $s) => self::catching($a, fn () => self::capabilitiesOut(self::clientOf($a, $s)->capabilities(), $a['keywords'] ?? self::KEYWORDS)),
            'client.command' => fn (array $a, Session $s) => self::catching($a, fn () => self::replyOut(self::clientOf($a, $s)->command($a['command'], $a['expect']))),
            'client.noop' => fn (array $a, Session $s) => self::catching($a, fn () => self::clientOf($a, $s)->noop()),
            'client.reset' => fn (array $a, Session $s) => self::catching($a, fn () => self::clientOf($a, $s)->reset()),
            'client.close' => fn (array $a, Session $s) => self::clientOf($a, $s)->close(),
            'client.ping' => fn (array $a, Session $s) => self::clientOf($a, $s)->ping(),
            'client.idle' => function (array $a, Session $s) {
                $idle = self::clientOf($a, $s)->idle();

                return is_infinite($idle) ? ['session' => false] : ['session' => true, 'under_a_second' => $idle >= 0.0 && $idle < 1.0];
            },
            'client.transactions' => fn (array $a, Session $s) => self::clientOf($a, $s)->transactions,

            'client.session' => function (array $a, Session $s) {
                ['client' => $client, 'transport' => $fake] = $s->get(self::client($a, $s));
                $result = self::catching(['catch' => true], fn () => self::resultOut($client->sendRaw(self::envelope($a['envelope']), $a['content'])));

                return ['result' => $result, 'written' => $fake->written, 'closed' => $fake->closed, 'transactions' => $client->transactions];
            },
            'fake.reply' => fn (array $a, Session $s) => self::fake($a, $s)->reply(...$a['lines']),
            'fake.state' => function (array $a, Session $s) {
                $fake = self::fake($a, $s);

                return [
                    'written' => $fake->written,
                    'commands' => $fake->commands(),
                    'connected' => $fake->connected,
                    'closed' => $fake->closed,
                    'handshakes' => $fake->handshakes,
                    'tls' => $fake->isTls(),
                    'waits' => $fake->waits,
                ];
            },

            'listener.new' => function (array $a, Session $s) {
                $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
                if ($server === false) {
                    throw new Fault("cannot listen: {$error}");
                }
                $address = (string) stream_socket_get_name($server, false);

                return $s->handle(new \ArrayObject(['server' => $server, 'port' => (int) substr($address, (int) strrpos($address, ':') + 1), 'peer' => null]));
            },
            'listener.accept' => function (array $a, Session $s) {
                $listener = $s->get($a['listener']);
                $peer = stream_socket_accept($listener['server'], 2);
                if ($peer === false) {
                    throw new Fault('the listener never saw the connection');
                }
                $listener['peer'] = $peer;

                return;
            },
            'listener.send' => fn (array $a, Session $s) => fwrite($s->get($a['listener'])['peer'], $a['data']),
            'listener.receive' => function (array $a, Session $s) {
                $peer = $s->get($a['listener'])['peer'];
                $read = '';
                while (\strlen($read) < $a['length']) {
                    $chunk = fread($peer, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $read .= $chunk;
                }

                return $read;
            },
            'listener.hangup' => function (array $a, Session $s) {
                $listener = $s->get($a['listener']);
                fclose($listener['peer']);
                $listener['peer'] = null;

                return;
            },

            'transport.new' => function (array $a, Session $s) {
                $port = isset($a['listener']) ? $s->get($a['listener'])['port'] : $a['port'];

                return $s->handle(new Native($a['host'] ?? '127.0.0.1', $port, self::tls($a['tls'] ?? [])));
            },
            'transport.connect' => fn (array $a, Session $s) => self::transport($a, $s)->connect($a['timeout'], $a['tls'] ?? false),
            'transport.read' => fn (array $a, Session $s) => self::transport($a, $s)->read($a['length'], $a['timeout']),
            'transport.write' => fn (array $a, Session $s) => self::transport($a, $s)->write($a['data'], $a['timeout']),
            'transport.start_tls' => fn (array $a, Session $s) => self::transport($a, $s)->startTls($a['timeout']),
            'transport.is_tls' => fn (array $a, Session $s) => self::transport($a, $s)->isTls(),
            'transport.close' => fn (array $a, Session $s) => self::transport($a, $s)->close(),

            'mailpit.clear' => function (array $a, Session $s) {
                self::http($s, $a['api'] ?? 'smtp-api', 'DELETE', '/api/v1/messages');

                return;
            },
            'mailpit.count' => fn (array $a, Session $s) => \count(self::messages($s, $a['api'] ?? 'smtp-api')),
            'mailpit.message' => function (array $a, Session $s) {
                $api = $a['api'] ?? 'smtp-api';
                $id = self::only($s, $api);
                $m = json_decode(self::http($s, $api, 'GET', "/api/v1/message/{$id}"), true);
                $files = static fn (array $list) => array_map(static fn (array $f) => [
                    'name' => $f['FileName'],
                    'type' => $f['ContentType'],
                    'cid' => $f['ContentID'],
                    'size' => $f['Size'],
                    'content' => self::http($s, $api, 'GET', "/api/v1/message/{$id}/part/{$f['PartID']}"),
                ], $list);
                $people = static fn (?array $list) => array_map(static fn (array $p) => [$p['Name'], $p['Address']], array_values(array_filter($list ?? [], is_array(...))));

                return [
                    'subject' => $m['Subject'],
                    'from' => $people([$m['From']]),
                    'to' => $people($m['To']),
                    'cc' => $people($m['Cc']),
                    'bcc' => $people($m['Bcc']),
                    'reply_to' => $people($m['ReplyTo']),
                    'return_path' => $m['ReturnPath'],
                    'text' => $m['Text'],
                    'html' => $m['HTML'],
                    'attachments' => $files($m['Attachments']),
                    'inline' => $files($m['Inline']),
                ];
            },
            'mailpit.raw' => function (array $a, Session $s) {
                $api = $a['api'] ?? 'smtp-api';
                $raw = self::http($s, $api, 'GET', '/api/v1/message/' . self::only($s, $api) . '/raw');
                $at = (int) strpos($raw, 'Date: ');
                $ours = substr($raw, $at);
                $bcc = null;
                foreach (explode("\r\n", substr($raw, 0, $at)) as $line) {
                    if (str_starts_with($line, 'Bcc: ')) {
                        $bcc = substr($line, 5);
                    }
                }

                return ['ours' => strtr($ours, self::boundaries($ours)), 'bcc' => $bcc, 'longest' => max(array_map(strlen(...), explode("\r\n", $ours)))];
            },
        ];
    }

    private static function address(array $a): Address
    {
        return isset($a['name']) ? new Address($a['email'], $a['name']) : new Address($a['email']);
    }

    private static function addressOut(Address $address): array
    {
        return ['email' => $address->email, 'name' => $address->name, 'string' => (string) $address, 'international' => $address->isInternational()];
    }

    private static function envelope(array $a): Envelope
    {
        return new Envelope($a['sender'], $a['recipients'], $a['utf8'] ?? false);
    }

    private static function envelopeOut(Envelope $envelope): array
    {
        return ['sender' => $envelope->sender, 'recipients' => $envelope->recipients, 'international' => $envelope->isInternational()];
    }

    private static function attachment(array $a): Attachment
    {
        if (isset($a['path'])) {
            $attachment = Attachment::fromPath($a['path'], $a['name'] ?? null, $a['type'] ?? null);
        } else {
            $attachment = isset($a['type'])
                ? Attachment::fromString($a['content'], $a['name'], $a['type'])
                : Attachment::fromString($a['content'], $a['name']);
        }

        return isset($a['cid']) ? $attachment->inline($a['cid']) : $attachment;
    }

    private static function message(array $m): Message
    {
        $from = self::address($m['from']);
        $to = array_map(self::address(...), $m['to'] ?? []);
        $cc = array_map(self::address(...), $m['cc'] ?? []);
        $bcc = array_map(self::address(...), $m['bcc'] ?? []);
        $replyTo = array_map(self::address(...), $m['reply_to'] ?? []);
        $attachments = array_map(self::attachment(...), $m['attachments'] ?? []);
        $date = null;
        if (isset($m['date'])) {
            $offset = $m['date']['offset'] ?? 0;
            $zone = \sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
            $date = (new \DateTimeImmutable('@' . $m['date']['timestamp']))->setTimezone(new \DateTimeZone($zone));
        }

        return new Message(
            from: $from,
            to: $to,
            subject: $m['subject'] ?? '',
            text: $m['text'] ?? null,
            html: $m['html'] ?? null,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            attachments: $attachments,
            headers: (array) ($m['headers'] ?? []),
            date: $date,
            messageId: $m['message_id'] ?? null,
        );
    }

    private static function part(array $p): Part
    {
        return match ($p['kind']) {
            'text' => Part::text($p['type'], $p['content']),
            'attachment' => Part::attachment(self::attachment($p['attachment'])),
            'multipart' => Part::multipart($p['subtype'], ...array_map(self::part(...), $p['parts'])),
            default => throw new Fault('unknown part ' . json_encode($p['kind'])),
        };
    }

    /**
     * Each random boundary (`=_` and 32 hex digits) and the name it gets, in
     * order of appearance.
     *
     * @return array<string, string>
     */
    private static function boundaries(string $rendered): array
    {
        $names = [];
        $offset = 0;
        while (($at = strpos($rendered, '=_', $offset)) !== false) {
            $candidate = substr($rendered, $at, 34);
            if (\strlen($candidate) === 34 && strspn($candidate, '0123456789abcdef', 2) === 32) {
                $names[$candidate] ??= '=_BOUNDARY' . (\count($names) + 1);
                $offset = $at + 34;
            } else {
                $offset = $at + 1;
            }
        }

        return $names;
    }

    /**
     * An exception of one of the library's classes, as a caller catching
     * \Throwable would see it.
     */
    private static function exception(array $a): \Throwable
    {
        return match ($a['class']) {
            'connection' => new ConnectionException($a['message']),
            'timeout' => new TimeoutException($a['message']),
            'protocol' => new ProtocolException($a['message']),
            'authentication' => new AuthenticationException($a['message']),
            'capability' => new CapabilityException($a['message']),
            'message' => new MessageException($a['message']),
            'transaction' => new TransactionException(
                new Reply($a['reply']['code'], $a['reply']['lines']),
                $a['message'] ?? '',
                array_map(static fn (array $r) => new Reply($r['code'], $r['lines']), $a['rejected'] ?? []),
            ),
            default => throw new Fault('unknown exception ' . json_encode($a['class'])),
        };
    }

    private static function replyOut(Reply $reply): array
    {
        return [
            'code' => $reply->code,
            'lines' => $reply->lines,
            'status' => $reply->status,
            'outcome' => $reply->outcome->name,
            'text' => $reply->text(),
            'string' => (string) $reply,
        ];
    }

    private static function capabilitiesOut(Capabilities $capabilities, array $keywords): array
    {
        return [
            'keywords' => array_map(static fn (string $k) => [$k, $capabilities->has($k), $capabilities->params($k)], $keywords),
            'max_size' => $capabilities->maxSize(),
            'mechanisms' => $capabilities->mechanisms(),
        ];
    }

    private static function resultOut(Result $result): array
    {
        $rejected = [];
        foreach ($result->rejected as $recipient => $reply) {
            $rejected[] = [(string) $recipient, self::replyOut($reply)];
        }

        return ['message_id' => $result->messageId, 'accepted' => $result->accepted, 'rejected' => $rejected, 'complete' => $result->isComplete()];
    }

    private static function errorOut(\Throwable $e): array
    {
        $out = ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()];
        if ($e instanceof TransactionException) {
            $rejected = [];
            foreach ($e->rejected as $recipient => $reply) {
                $rejected[] = [(string) $recipient, self::replyOut($reply)];
            }
            $out += ['reply' => self::replyOut($e->reply), 'rejected' => $rejected, 'transient' => $e->isTransient(), 'permanent' => $e->isPermanent()];
        }

        return $out;
    }

    /**
     * With `catch`, a runtime failure is returned as its details rather than
     * raised, so its reply and refusals are compared too.
     */
    private static function catching(array $a, callable $call): mixed
    {
        if (!($a['catch'] ?? false)) {
            return $call();
        }
        try {
            return ['ok' => $call()];
        } catch (SmtpException $e) {
            return ['error' => self::errorOut($e)];
        }
    }

    private static function tls(array $t): Tls
    {
        return new Tls(
            verify: match ($t['verify'] ?? 'full') {
                'full' => Verification::Full,
                'self_signed' => Verification::SelfSigned,
                'none' => Verification::None,
                default => throw new Fault('unknown verification ' . json_encode($t['verify'])),
            },
            peerName: $t['peer_name'] ?? null,
            caFile: $t['ca_file'] ?? null,
            ciphers: $t['ciphers'] ?? null,
        );
    }

    private static function authenticator(array $a): Authenticator
    {
        return match ($a['mechanism']) {
            'plain' => new Plain($a['username'], $a['secret']),
            'login' => new Login($a['username'], $a['secret']),
            'xoauth2' => new XOAuth2($a['username'], $a['secret']),
            default => throw new Fault('unknown mechanism ' . json_encode($a['mechanism'])),
        };
    }

    private static function native(Session $s, array $t): Native
    {
        $address = $s->service($t['service']);
        $at = (int) strrpos($address, ':');

        return new Native(substr($address, 0, $at), (int) substr($address, $at + 1), self::tls($t));
    }

    private static function client(array $a, Session $s): array
    {
        $t = $a['transport'] ?? [];
        $transport = isset($t['service'])
            ? self::native($s, $t)
            : new Recording(
                $t['replies'] ?? [],
                failHandshake: $t['fail_handshake'] ?? false,
                greedy: $t['greedy'] ?? false,
                afterHandshake: $t['after_handshake'] ?? [],
                failWriting: $t['fail_writing'] ?? null,
                chunk: $t['chunk'] ?? PHP_INT_MAX,
            );
        $timeouts = isset($a['timeouts']) ? new Timeouts(...$a['timeouts']) : new Timeouts();
        $client = new Client(
            $transport,
            $a['domain'] ?? 'localhost',
            array_map(self::authenticator(...), $a['authenticators'] ?? []),
            match ($a['encryption'] ?? 'opportunistic') {
                'none' => Encryption::None,
                'opportunistic' => Encryption::Opportunistic,
                'starttls' => Encryption::StartTls,
                'implicit' => Encryption::Implicit,
                default => throw new Fault('unknown encryption ' . json_encode($a['encryption'])),
            },
            $timeouts,
        );

        return $s->handle(['client' => $client, 'transport' => $transport]);
    }

    private static function clientOf(array $a, Session $s): Client
    {
        return $s->get($a['client'])['client'];
    }

    private static function fake(array $a, Session $s): Recording
    {
        $transport = $s->get($a['client'])['transport'];

        return $transport instanceof Recording ? $transport : throw new Fault('not a scripted client');
    }

    private static function transport(array $a, Session $s): Native
    {
        $transport = $s->get($a['transport']);

        return $transport instanceof Native ? $transport : throw new Fault('not a transport');
    }

    private static function http(Session $s, string $api, string $method, string $path): string
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true]]);
        $body = @file_get_contents($s->service($api) . $path, false, $context);

        return $body === false ? throw new Fault("Mailpit did not answer {$method} {$path}") : $body;
    }

    private static function messages(Session $s, string $api): array
    {
        return json_decode(self::http($s, $api, 'GET', '/api/v1/messages'), true)['messages'] ?? throw new Fault('no message list');
    }

    private static function only(Session $s, string $api): string
    {
        $messages = self::messages($s, $api);

        return \count($messages) === 1 ? $messages[0]['ID'] : throw new Fault('expected exactly one delivered message, got ' . \count($messages));
    }
}
