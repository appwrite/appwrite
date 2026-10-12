<?php

namespace Tests\Compat\Dns;

use Utopia\DNS\Message;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Resolver\Proxy;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\File;
use Utopia\DNS\Zone\Resolver as ZoneResolver;

/**
 * The resolvers the compat DNS servers answer with (the Rust twin is
 * `fixture` in crates/tools/compat/src/libs/dns.rs):
 *
 * - `fixture`: the zones of packages/dns/tests/resources/server.php
 *   (appwrite.io, and localhost from its zone file), first zone's SOA in
 *   NXDOMAIN answers for other names;
 * - `zone`: a Memory resolver of the zone given;
 * - `rules`: an answer per question type: `{"<type>": {"rcode": n, "answer": rdata}}`, `*` for others;
 * - `proxy`: a Proxy resolver forwarding to the upstream server's UDP port;
 * - `throw`: a resolver that throws;
 * - `invalid`: a resolver answering with an A record that cannot be encoded.
 */
final class Fixture
{
    /**
     * @param array<string, mixed> $spec
     * @param array{0: string, 1: int}|null $upstream
     */
    public static function resolver(array $spec, ?array $upstream): Resolver
    {
        return match ($spec['kind']) {
            'fixture' => self::fixture(),
            'zone' => new Memory(self::zone($spec['zone'])),
            'rules' => new class ($spec['rules']) implements Resolver {
                public function __construct(private array $rules)
                {
                }

                public function resolve(Query $query): Message
                {
                    $question = $query->message->questions[0];
                    $rule = $this->rules[(string) $question->type] ?? $this->rules['*'] ?? ['rcode' => 0];
                    $answers = isset($rule['answer'])
                        ? [new Record($question->name, $question->type, ttl: 60, rdata: $rule['answer'])]
                        : [];

                    return Message::response($query->message->header, $rule['rcode'] ?? 0, $query->message->questions, $answers);
                }
            },
            'proxy' => new Proxy($upstream[0], $upstream[1]),
            'throw' => new class () implements Resolver {
                public function resolve(Query $query): Message
                {
                    throw new \Exception('Resolver failed for ' . $query->protocol->value);
                }
            },
            'invalid' => new class () implements Resolver {
                public function resolve(Query $query): Message
                {
                    return Message::response(
                        $query->message->header,
                        Message::RCODE_NOERROR,
                        $query->message->questions,
                        [new Record('invalid.test', Record::TYPE_A, ttl: 60, rdata: 'not-an-ip')],
                    );
                }
            },
            default => throw new \LogicException('unknown resolver ' . $spec['kind']),
        };
    }

    /**
     * @param array<string, mixed> $zone {name, records, soa}
     */
    public static function zone(array $zone): Zone
    {
        return new Zone($zone['name'], array_map(self::record(...), $zone['records'] ?? []), self::record($zone['soa']));
    }

    /**
     * @param array<string, mixed> $r
     */
    public static function record(array $r): Record
    {
        return new Record(
            $r['name'],
            $r['type'],
            $r['class'] ?? Record::CLASS_IN,
            $r['ttl'] ?? 0,
            $r['rdata'] ?? '',
            $r['priority'] ?? null,
            $r['weight'] ?? null,
            $r['port'] ?? null,
        );
    }

    private static function fixture(): Resolver
    {
        $records = [
            new Record(name: 'dev.appwrite.io', type: Record::TYPE_A, ttl: 10, rdata: '180.12.3.24'),
            new Record(name: 'dev2.appwrite.io', type: Record::TYPE_A, ttl: 1800, rdata: '142.6.0.1'),
            new Record(name: 'dev2.appwrite.io', type: Record::TYPE_A, ttl: 1800, rdata: '142.6.0.2'),
            new Record(name: 'dev.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0042:8329'),
            new Record(name: 'dev2.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0000:0001'),
            new Record(name: 'dev2.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0000:0002'),
            new Record(name: 'alias.appwrite.io', type: Record::TYPE_CNAME, ttl: 30, rdata: 'cloud.appwrite.io'),
            new Record(name: 'dev.appwrite.io', type: Record::TYPE_TXT, ttl: 30, rdata: 'awesome-secret-key'),
            new Record(name: 'dev.appwrite.io', type: Record::TYPE_MX, ttl: 30, rdata: '10 mail.appwrite.io'),
            new Record(name: 'dev.appwrite.io', type: Record::TYPE_CAA, ttl: 30, rdata: '0 issue "letsencrypt.org"'),
            new Record(name: 'delegated.appwrite.io', type: Record::TYPE_NS, ttl: 30, rdata: 'ns1.test.io'),
            new Record(name: 'delegated.appwrite.io', type: Record::TYPE_NS, ttl: 30, rdata: 'ns2.test.io'),
        ];
        $appwrite = new Zone(
            name: 'appwrite.io',
            records: $records,
            soa: new Record(name: 'appwrite.io', type: Record::TYPE_SOA, ttl: 30, rdata: 'ns1.appwrite.zone team.appwrite.io 1 7200 1800 1209600 3600'),
        );
        $localhost = File::import((string) file_get_contents('/usr/src/code/packages/dns/tests/resources/zone-valid-localhost.txt'));

        return new readonly class ([$appwrite, $localhost]) implements Resolver {
            /** @param list<Zone> $zones */
            public function __construct(private array $zones)
            {
            }

            public function resolve(Query $query): Message
            {
                $message = $query->message;
                $question = $message->questions[0] ?? null;
                if ($question === null) {
                    return Message::response(header: $message->header, responseCode: Message::RCODE_FORMERR, authoritative: true);
                }
                $name = strtolower($question->name);
                foreach ($this->zones as $zone) {
                    if ($name === $zone->name || str_ends_with($name, '.' . $zone->name)) {
                        return ZoneResolver::lookup($message, $zone);
                    }
                }

                return Message::response(
                    header: $message->header,
                    responseCode: Message::RCODE_NXDOMAIN,
                    questions: $message->questions,
                    authority: [$this->zones[0]->soa],
                    authoritative: true,
                );
            }
        };
    }
}
