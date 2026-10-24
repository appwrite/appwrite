<?php

namespace Tests\Compat\Dns;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\DNS\Adapter\Native;
use Utopia\DNS\Adapter\Swoole;
use Utopia\DNS\Client;
use Utopia\DNS\Exception\Message\DecodingException;
use Utopia\DNS\Exception\Message\PartialDecodingException;
use Utopia\DNS\Exception\Zone\ImportException;
use Utopia\DNS\Lookup\Recursive;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Domain;
use Utopia\DNS\Message\Header;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Protocol;
use Utopia\DNS\ProxyProtocol;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver\Cloudflare;
use Utopia\DNS\Resolver\Google;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Resolver\Proxy;
use Utopia\DNS\Validator\CAA;
use Utopia\DNS\Validator\DNS;
use Utopia\DNS\Validator\Name;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\File;
use Utopia\DNS\Zone\Resolver as ZoneResolver;
use Utopia\Validator\Validator;

require_once __DIR__ . '/fixtures/Fixture.php';

/**
 * Maps tests/compat/dns/spec.json operations onto utopia-php/dns. Glue only: no logic.
 *
 * Messages, records and zones cross as JSON objects (the dump of each class's
 * public properties); `server.run` starts fixtures/server.php servers on
 * local ports and runs client steps against them.
 */
final class Adapter implements Base, Benchmarks
{
    /** A response with an answer of each common type, as a server sends it. */
    private const string PACKET = '9w6BgAABAAQAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQABwAwAAQABAAABLAAEXbjYIsAMAAEAAQAAASwABF242CPADAAcAAEAAAEsABAgAQ24AAAAAAAAAAAAAAABwAwAEAABAAABLAAMC2hlbGxvIHdvcmxk';

    public function benchmarks(): array
    {
        $packet = (string) base64_decode(self::PACKET);
        $zone = Fixture::zone([
            'name' => 'example.com',
            'records' => [
                ['name' => 'www.example.com', 'type' => 1, 'ttl' => 300, 'rdata' => '93.184.216.34'],
                ['name' => 'www.example.com', 'type' => 28, 'ttl' => 300, 'rdata' => '2001:db8::1'],
                ['name' => '*.apps.example.com', 'type' => 5, 'ttl' => 300, 'rdata' => 'edge.example.net'],
                ['name' => 'example.com', 'type' => 15, 'ttl' => 300, 'rdata' => 'mail.example.com', 'priority' => 10],
            ],
            'soa' => ['name' => 'example.com', 'type' => 6, 'ttl' => 300, 'rdata' => 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300'],
        ]);
        $query = Message::query(new Question('www.example.com', Record::TYPE_A), id: 7);
        $wildcard = Message::query(new Question('site.apps.example.com', Record::TYPE_A), id: 8);
        $message = Message::decode($packet);
        mt_srand(1);

        return [
            'decode' => ['Decode a response with A, AAAA and TXT answers', fn (Session $s) => fn () => Message::decode($packet)],
            'encode' => ['Encode that response within 512 bytes', fn (Session $s) => fn () => $message->encode(512)],
            'lookup' => ['Answer an A query from a zone', fn (Session $s) => fn () => ZoneResolver::lookup($query, $zone)],
            'lookup_wildcard' => ['Answer a query from a zone\'s wildcard CNAME', fn (Session $s) => fn () => ZoneResolver::lookup($wildcard, $zone)],
            'serve' => ['Decode a query, answer it from a zone and encode the response', fn (Session $s) => function () use ($query, $zone) {
                $decoded = Message::decode($query->encode());

                return ZoneResolver::lookup($decoded, $zone)->encode(512);
            }],
            'name' => ['Validate a record name', fn (Session $s) => fn () => (new Name(Record::TYPE_CNAME))->isValid('_dmarc.mail.example.com')],
        ];
    }

    public function operations(): array
    {
        return [
            'header.new' => fn (array $a, Session $s) => self::dumpHeader(self::header($a['header'])),
            'header.encode' => fn (array $a, Session $s) => self::header($a['header'])->encode(),
            'header.decode' => fn (array $a, Session $s) => self::dumpHeader(Header::decode($a['data'], $a['offset'] ?? 0)),

            'domain.encode' => fn (array $a, Session $s) => Domain::encode($a['name']),
            'domain.decode' => function (array $a, Session $s) {
                $offset = $a['offset'] ?? 0;
                $name = Domain::decode($a['data'], $offset);

                return ['name' => $name, 'offset' => $offset];
            },

            'question.new' => fn (array $a, Session $s) => self::dumpQuestion(self::question($a['question'])),
            'question.encode' => fn (array $a, Session $s) => self::question($a['question'])->encode(),
            'question.decode' => function (array $a, Session $s) {
                $offset = $a['offset'] ?? 0;
                $question = Question::decode($a['data'], $offset);

                return ['question' => self::dumpQuestion($question), 'offset' => $offset];
            },

            'record.new' => fn (array $a, Session $s) => self::dumpRecord(Fixture::record($a['record'])),
            'record.encode' => fn (array $a, Session $s) => Fixture::record($a['record'])->encode(),
            'record.decode' => function (array $a, Session $s) {
                $offset = $a['offset'] ?? 0;
                $record = Record::decode($a['data'], $offset);

                return ['record' => self::dumpRecord($record), 'offset' => $offset];
            },
            'record.validate' => fn (array $a, Session $s) => Fixture::record($a['record'])->validateRdata(),
            'record.with_name' => fn (array $a, Session $s) => self::dumpRecord(Fixture::record($a['record'])->withName($a['name'])),
            'record.type_name_to_code' => fn (array $a, Session $s) => Record::typeNameToCode($a['name']),
            'record.type_code_to_name' => fn (array $a, Session $s) => Record::typeCodeToName($a['code']),

            'message.new' => fn (array $a, Session $s) => self::dumpMessage(self::message($a['message'])),
            'message.query' => fn (array $a, Session $s) => self::dumpMessage(Message::query(
                self::question($a['question']),
                $a['id'] ?? null,
                $a['recursionDesired'] ?? true,
            )),
            'message.response' => fn (array $a, Session $s) => self::dumpMessage(Message::response(
                self::header($a['header']),
                $a['responseCode'],
                array_map(self::question(...), $a['questions'] ?? []),
                array_map(Fixture::record(...), $a['answers'] ?? []),
                array_map(Fixture::record(...), $a['authority'] ?? []),
                array_map(Fixture::record(...), $a['additional'] ?? []),
                $a['authoritative'] ?? false,
                $a['truncated'] ?? false,
                $a['recursionAvailable'] ?? false,
            )),
            'message.decode' => fn (array $a, Session $s) => self::dumpMessage(Message::decode($a['packet'])),
            'message.decode_error' => function (array $a, Session $s) {
                try {
                    Message::decode($a['packet']);

                    return;
                } catch (DecodingException $e) {
                    return [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'header' => $e instanceof PartialDecodingException ? self::dumpHeader($e->getHeader()) : null,
                    ];
                }
            },
            'message.encode' => fn (array $a, Session $s) => self::message($a['message'])->encode($a['maxSize'] ?? null),
            'message.validate' => fn (array $a, Session $s) => self::message($a['message'])->validate(),

            'zone.new' => fn (array $a, Session $s) => self::dumpZone(Fixture::zone($a['zone'])),
            'zone.is_authoritative' => fn (array $a, Session $s) => Fixture::zone($a['zone'])->isAuthoritative($a['name']),
            'zone.lookup' => function (array $a, Session $s) {
                $zone = Fixture::zone($a['zone']);
                $query = self::message($a['query']);
                mt_srand($a['seed'] ?? 1);

                return self::dumpMessage(ZoneResolver::lookup($query, $zone));
            },
            'memory.resolve' => function (array $a, Session $s) {
                $resolver = new Memory(Fixture::zone($a['zone']));
                $query = new Query(self::message($a['query']), $a['ip'] ?? '127.0.0.1', $a['port'] ?? 53, Protocol::from($a['protocol'] ?? 'udp'));
                mt_srand($a['seed'] ?? 1);

                return self::dumpMessage($resolver->resolve($query));
            },
            'query.new' => function (array $a, Session $s) {
                $query = new Query(self::message($a['query']), $a['ip'], $a['port'], Protocol::from($a['protocol']));

                return [
                    'message' => self::dumpMessage($query->message),
                    'ip' => $query->ip,
                    'port' => $query->port,
                    'protocol' => $query->protocol->value,
                    'maxResponseSize' => $query->protocol->maxResponseSize(),
                ];
            },

            'file.import' => fn (array $a, Session $s) => self::dumpZone(File::import($a['content'], $a['defaultOrigin'] ?? null, $a['defaultTTL'] ?? 3600)),
            'file.import_error' => function (array $a, Session $s) {
                try {
                    File::import($a['content'], $a['defaultOrigin'] ?? null, $a['defaultTTL'] ?? 3600);

                    return;
                } catch (ImportException $e) {
                    $previous = $e->getPrevious();

                    return [
                        'message' => $e->getMessage(),
                        'content' => $e->getContent(),
                        'previous' => $previous === null ? null : ['class' => $previous::class, 'message' => $previous->getMessage()],
                    ];
                }
            },
            // The export as lines: the second is the generation time when comments are included.
            'file.export' => fn (array $a, Session $s) => explode("\n", File::export(Fixture::zone($a['zone']), $a['includeComments'] ?? true)),

            'proxy.parse' => function (array $a, Session $s) {
                $header = ProxyProtocol::parse($a['buffer']);

                return $header === null ? null : ['length' => $header->length, 'ip' => $header->ip, 'port' => $header->port];
            },

            'validator.check' => function (array $a, Session $s) {
                $validator = match ($a['validator']) {
                    'name' => new Name($a['recordType'] ?? null),
                    'caa' => new CAA(),
                    default => throw new Fault('unknown validator ' . $a['validator']),
                };

                return array_map(fn ($value) => [
                    'valid' => $validator->isValid($value),
                    'description' => $validator->getDescription(),
                ], $a['values']);
            },
            'validator.describe' => function (array $a, Session $s) {
                $validator = match ($a['validator']) {
                    'name' => new Name($a['recordType'] ?? null),
                    'caa' => new CAA(),
                    'dns' => new DNS($a['target'] ?? '', $a['type'] ?? Record::TYPE_CNAME, $a['server'] ?? '8.8.8.8'),
                    default => throw new Fault('unknown validator ' . $a['validator']),
                };

                return ['description' => $validator->getDescription(), 'type' => $validator->getType(), 'array' => $validator->isArray()];
            },

            'client.new' => function (array $a, Session $s) {
                new Client($a['server'], $a['port'] ?? 53, $a['timeout'] ?? 5, $a['tcp'] ?? false);

                return;
            },
            'lookup.new' => function (array $a, Session $s) {
                new Recursive($a['servers']);

                return;
            },
            'resolver.new' => function (array $a, Session $s) {
                match ($a['resolver']) {
                    'proxy' => new Proxy($a['server'], $a['port'] ?? 53),
                    'google' => new Google($a['backup'] ?? false),
                    'cloudflare' => new Cloudflare($a['backup'] ?? false),
                    default => throw new Fault('unknown resolver'),
                };

                return;
            },
            'adapter.new' => function (array $a, Session $s) {
                match ($a['adapter']) {
                    'native' => new Native($a['transports'] ?? []),
                    'swoole' => new Swoole($a['transports'] ?? []),
                    'http' => new Swoole\Http('127.0.0.1', 8443, $a['cert'] ?? null, $a['key'] ?? null),
                    default => throw new Fault('unknown adapter'),
                };

                return;
            },

            'server.run' => fn (array $a, Session $s) => Servers::run($a['servers'], $a['steps']),
            // Interop: PHP's server is the services container (tests/compat/dns/services.sh), which
            // serves the fixture zones; Rust starts its own. `peer.run` asks the server of the
            // runtime `peer.serve` named, at this runtime's address for it (`dns-<runtime>-server`).
            'peer.serve' => fn (array $a, Session $s) => ['runtime' => 'php'],
            'peer.run' => fn (array $a, Session $s) => Servers::peer($s->service('dns-' . $a['peer']['runtime'] . '-server'), $a['steps']),
        ];
    }

    /**
     * @param array<string, mixed> $h
     */
    private static function header(array $h): Header
    {
        return new Header(
            id: $h['id'] ?? 0,
            isResponse: $h['isResponse'] ?? false,
            opcode: $h['opcode'] ?? 0,
            authoritative: $h['authoritative'] ?? false,
            truncated: $h['truncated'] ?? false,
            recursionDesired: $h['recursionDesired'] ?? false,
            recursionAvailable: $h['recursionAvailable'] ?? false,
            responseCode: $h['responseCode'] ?? 0,
            questionCount: $h['questionCount'] ?? 0,
            answerCount: $h['answerCount'] ?? 0,
            authorityCount: $h['authorityCount'] ?? 0,
            additionalCount: $h['additionalCount'] ?? 0,
        );
    }

    /**
     * @param array<string, mixed> $q
     */
    private static function question(array $q): Question
    {
        return new Question($q['name'], $q['type'], $q['class'] ?? Record::CLASS_IN);
    }

    /**
     * @param array<string, mixed> $m
     */
    public static function message(array $m): Message
    {
        return new Message(
            self::header($m['header']),
            array_map(self::question(...), $m['questions'] ?? []),
            array_map(Fixture::record(...), $m['answers'] ?? []),
            array_map(Fixture::record(...), $m['authority'] ?? []),
            array_map(Fixture::record(...), $m['additional'] ?? []),
        );
    }

    public static function dumpHeader(Header $h): array
    {
        return [
            'id' => $h->id,
            'isResponse' => $h->isResponse,
            'opcode' => $h->opcode,
            'authoritative' => $h->authoritative,
            'truncated' => $h->truncated,
            'recursionDesired' => $h->recursionDesired,
            'recursionAvailable' => $h->recursionAvailable,
            'responseCode' => $h->responseCode,
            'questionCount' => $h->questionCount,
            'answerCount' => $h->answerCount,
            'authorityCount' => $h->authorityCount,
            'additionalCount' => $h->additionalCount,
        ];
    }

    public static function dumpQuestion(Question $q): array
    {
        return ['name' => $q->name, 'type' => $q->type, 'class' => $q->class];
    }

    public static function dumpRecord(Record $r): array
    {
        return [
            'name' => $r->name,
            'type' => $r->type,
            'class' => $r->class,
            'ttl' => $r->ttl,
            'rdata' => $r->rdata,
            'priority' => $r->priority,
            'weight' => $r->weight,
            'port' => $r->port,
        ];
    }

    public static function dumpMessage(Message $m): array
    {
        return [
            'header' => self::dumpHeader($m->header),
            'questions' => array_map(self::dumpQuestion(...), $m->questions),
            'answers' => array_map(self::dumpRecord(...), $m->answers),
            'authority' => array_map(self::dumpRecord(...), $m->authority),
            'additional' => array_map(self::dumpRecord(...), $m->additional),
        ];
    }

    private static function dumpZone(Zone $z): array
    {
        return ['name' => $z->name, 'records' => array_map(self::dumpRecord(...), $z->records), 'soa' => self::dumpRecord($z->soa)];
    }
}

/**
 * Starts fixture servers (fixtures/server.php) on free local ports, runs
 * client steps against them, and stops them.
 */
final class Servers
{
    /**
     * @param list<array<string, mixed>> $specs
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    public static function run(array $specs, array $steps): array
    {
        $servers = [];
        try {
            foreach ($specs as $spec) {
                $servers[] = self::start($spec, $servers);
            }
            foreach ($servers as $server) {
                self::ready($server);
            }
            $out = [];
            foreach ($steps as $step) {
                try {
                    $out[] = self::step($step, $servers);
                } catch (Fault $fault) {
                    throw $fault;
                } catch (\Throwable $e) {
                    $out[] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                }
            }

            return $out;
        } finally {
            foreach ($servers as $server) {
                self::stop($server);
            }
        }
    }

    /**
     * Runs steps against the server at `host:port` (UDP and TCP on the
     * port, HTTP on the next, PROXY-protocol TCP on the one after).
     *
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    public static function peer(string $endpoint, array $steps): array
    {
        [$host, $port] = explode(':', $endpoint, 2);
        $server = ['host' => gethostbyname($host), 'ports' => ['udp' => (int) $port, 'tcp' => (int) $port, 'http' => (int) $port + 1, 'proxy' => (int) $port + 2]];
        $out = [];
        foreach ($steps as $step) {
            try {
                $out[] = self::step($step + ['server' => 0], [$server]);
            } catch (Fault $fault) {
                throw $fault;
            } catch (\Throwable $e) {
                $out[] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
            }
        }

        return $out;
    }

    /** How many complete length-prefixed frames `$data` holds. */
    private static function frames(string $data): int
    {
        $count = 0;
        $at = 0;
        while (\strlen($data) >= $at + 2) {
            $length = unpack('n', substr($data, $at, 2))[1];
            if (\strlen($data) < $at + 2 + $length) {
                break;
            }
            $at += 2 + $length;
            $count++;
        }

        return $count;
    }

    private static function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new Fault('no free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<array<string, mixed>> $servers
     * @return array<string, mixed>
     */
    private static function start(array $spec, array $servers): array
    {
        $state = sys_get_temp_dir() . '/compat-dns-' . bin2hex(random_bytes(6));
        mkdir($state);
        $ports = [];
        foreach ($spec['transports'] ?? ['udp', 'tcp'] as $transport) {
            $ports[$transport] = self::port();
        }
        if (isset($ports['udp'], $ports['tcp'])) {
            $ports['udp'] = $ports['tcp'];
        }
        if ($spec['port53'] ?? false) {
            $ports['udp53'] = 53;
        }
        $upstream = isset($spec['upstream']) ? ['127.0.0.1', $servers[$spec['upstream']]['ports']['udp']] : null;
        $config = [
            'state' => $state,
            'adapter' => $spec['adapter'] ?? 'native',
            'ports' => $ports,
            'resolver' => $spec['resolver'] ?? ['kind' => 'fixture'],
            'upstream' => $upstream,
            'seed' => $spec['seed'] ?? 1,
            'tcp' => $spec['tcp'] ?? [],
            'telemetry' => $spec['telemetry'] ?? false,
            'trustProxy' => $spec['trustProxy'] ?? false,
        ];
        file_put_contents($state . '/config.json', json_encode($config));
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/server.php', $state . '/config.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $state . '/stdout', 'w'], 2 => ['file', $state . '/stderr', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new Fault('cannot start the fixture server');
        }

        return ['process' => $process, 'state' => $state, 'ports' => $ports, 'spec' => $spec];
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function ready(array $server): void
    {
        for ($i = 0; $i < 1200; $i++) {
            if (is_file($server['state'] . '/workers')) {
                $ok = true;
                foreach (['tcp', 'proxy', 'http'] as $kind) {
                    if (isset($server['ports'][$kind])) {
                        $socket = @stream_socket_client('tcp://127.0.0.1:' . $server['ports'][$kind], $errno, $errstr, 1);
                        if ($socket === false) {
                            $ok = false;
                        } else {
                            fclose($socket);
                        }
                    }
                }
                if ($ok) {
                    return;
                }
            }
            $status = proc_get_status($server['process']);
            if (!$status['running']) {
                throw new Fault('the fixture server exited: ' . file_get_contents($server['state'] . '/stderr'));
            }
            usleep(25_000);
        }
        throw new Fault('the fixture server did not start: ' . file_get_contents($server['state'] . '/stderr'));
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function stop(array $server): void
    {
        $status = proc_get_status($server['process']);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
            for ($i = 0; $i < 100 && proc_get_status($server['process'])['running']; $i++) {
                usleep(20_000);
            }
            if (proc_get_status($server['process'])['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
        }
        proc_close($server['process']);
        foreach (glob($server['state'] . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($server['state']);
    }

    /**
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $servers
     */
    private static function step(array $step, array $servers): mixed
    {
        $server = $servers[$step['server'] ?? 0] ?? null;
        $host = $server['host'] ?? '127.0.0.1';
        $port = static fn (string $kind): int => $server['ports'][$kind] ?? throw new Fault("the server has no {$kind} transport");

        switch ($step['kind']) {
            case 'query':
                $tcp = ($step['transport'] ?? 'udp') === 'tcp';
                $client = new Client($host, $port($tcp ? 'tcp' : 'udp'), $step['timeout'] ?? 2, $tcp);
                $message = Message::query(
                    new Question($step['name'], $step['type'], $step['class'] ?? Record::CLASS_IN),
                    $step['id'] ?? 1,
                    $step['recursionDesired'] ?? true,
                );

                return Adapter::dumpMessage($client->query($message));

            case 'raw':
                $transport = $step['transport'] ?? 'udp';
                if ($transport === 'udp') {
                    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
                    socket_sendto($socket, $step['data'], \strlen($step['data']), 0, $host, $port('udp'));
                    $data = '';
                    $from = '';
                    $from_port = 0;
                    $result = @socket_recvfrom($socket, $data, 65535, 0, $from, $from_port);
                    socket_close($socket);

                    return $result === false ? null : $data;
                }
                $socket = stream_socket_client('tcp://' . $host . ':' . $port($transport), $errno, $errstr, 2);
                if ($socket === false) {
                    throw new Fault('cannot connect: ' . $errstr);
                }
                stream_set_timeout($socket, 0, (int) (($step['wait'] ?? 0.5) * 1_000_000));
                foreach ((array) ($step['chunks'] ?? [$step['data']]) as $chunk) {
                    fwrite($socket, $chunk);
                    usleep(20_000);
                }
                // Until the connection closes, `frames` complete answers arrive
                // (waiting up to 5 seconds), or nothing arrives for `wait` seconds.
                $data = '';
                $closed = false;
                $frames = $step['frames'] ?? null;
                $deadline = microtime(true) + 5;
                while ($frames === null || self::frames($data) < $frames) {
                    $chunk = fread($socket, 65536);
                    if ($chunk === false || $chunk === '') {
                        if (stream_get_meta_data($socket)['eof']) {
                            $closed = true;
                            break;
                        }
                        if ($frames !== null && microtime(true) < $deadline) {
                            continue;
                        }
                        break;
                    }
                    $data .= $chunk;
                }
                fclose($socket);

                return ['data' => $data, 'closed' => $closed];

            case 'doh':
                $url = 'http://' . $host . ':' . $port('http') . ($step['path'] ?? '/');
                $headers = [];
                if (isset($step['contentType'])) {
                    $headers[] = 'Content-Type: ' . $step['contentType'];
                }
                foreach ($step['headers'] ?? [] as $name => $value) {
                    $headers[] = $name . ': ' . $value;
                }
                $context = stream_context_create(['http' => [
                    'method' => $step['method'] ?? 'GET',
                    'header' => $headers,
                    'content' => $step['body'] ?? '',
                    'ignore_errors' => true,
                    'timeout' => 5,
                    'protocol_version' => 1.1,
                ]]);
                $body = @file_get_contents($url, false, $context);
                $status = 0;
                $contentType = null;
                foreach (http_get_last_response_headers() ?? [] as $line) {
                    if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m) === 1) {
                        $status = (int) $m[1];
                    } elseif (stripos($line, 'content-type:') === 0) {
                        $contentType = trim(substr($line, 13));
                    }
                }

                return ['status' => $status, 'contentType' => $contentType, 'body' => $body === false ? null : $body];

            case 'recursive':
                $addresses = [];
                foreach ($step['servers'] as $target) {
                    $addresses[] = $target === 'unreachable'
                        ? '127.0.0.1:' . self::port()
                        : '127.0.0.1:' . $servers[$target]['ports']['tcp'];
                }

                return (new Recursive($addresses))->addresses($step['hostname']);

            case 'proxy':
                $resolver = new Proxy($host, $port('udp'));
                $message = Message::query(new Question($step['name'], $step['type']), $step['id'] ?? 1);

                return Adapter::dumpMessage($resolver->resolve(new Query($message, '127.0.0.1', 5353, Protocol::Udp)));

            case 'validate':
                // PHP's validator asks port 53: the server spec sets `port53`.
                $validator = new DNS($step['target'], $step['type'], '127.0.0.1');

                return array_map(static function ($value) use ($validator) {
                    try {
                        $valid = $validator->isValid($value);
                    } catch (\Throwable $e) {
                        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                    }

                    return [
                        'valid' => $valid,
                        'description' => $validator->getDescription(),
                        'records' => $validator->records,
                        'value' => $validator->value,
                        'count' => $validator->count,
                        'reason' => $validator->reason,
                    ];
                }, $step['values']);

            case 'errors':
            case 'workers':
            case 'telemetry':
                usleep(50_000);
                $file = $server['state'] . '/' . $step['kind'];
                $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

                return array_map(static fn (string $line) => json_decode($line, true), $lines ?: []);

            case 'sleep':
                usleep((int) ($step['ms'] * 1000));

                return null;

            default:
                throw new Fault('unknown step ' . $step['kind']);
        }
    }
}
