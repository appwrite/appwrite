<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Record;

/**
 * A DNS server on one loopback address answering from a fixed table. It runs in a child
 * process so the validator's blocking UDP client is served the way a real server serves it.
 */
final class FakeNameserver
{
    private const int RCODE_NOERROR = 0;
    private const int RCODE_NXDOMAIN = 3;

    private ?Process $process = null;

    private ?\Socket $socket = null;

    /**
     * @param array<string, array<int, array<string>>> $zone name => [record type => rdata list].
     *  A name that is absent is NXDOMAIN. A name without the asked type is an empty answer,
     *  unless it has a CNAME, which is returned instead, as a real server does.
     * @param bool $authoritative Whether answers carry the AA flag, as a zone's own nameserver's do
     */
    public function __construct(
        private readonly string $ip,
        private readonly array $zone,
        private readonly bool $authoritative,
    ) {
    }

    public function start(): void
    {
        $socket = \socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false || !@\socket_bind($socket, $this->ip, 53)) {
            throw new \RuntimeException("Cannot listen on {$this->ip}:53: " . \socket_strerror(\socket_last_error()));
        }
        $this->socket = $socket;

        $this->process = new Process(function () use ($socket): void {
            $packet = '';
            $from = '';
            $port = 0;
            while (\socket_recvfrom($socket, $packet, 512, 0, $from, $port) !== false) {
                try {
                    $response = $this->answer(Message::decode($packet))->encode();
                } catch (\Throwable) {
                    continue;
                }

                \socket_sendto($socket, $response, \strlen($response), 0, $from, $port);
            }
        }, false, 0, false);
        $this->process->start();
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            Process::kill($this->process->pid, 9);
            Process::wait(true);
            $this->process = null;
        }

        if ($this->socket !== null) {
            \socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function answer(Message $query): Message
    {
        $question = $query->questions[0];
        $records = $this->zone[$question->name] ?? null;

        if ($records === null) {
            return Message::response(
                $query->header,
                self::RCODE_NXDOMAIN,
                $query->questions,
                authoritative: $this->authoritative,
                recursionAvailable: !$this->authoritative,
            );
        }

        $type = $question->type;
        if (!isset($records[$type]) && isset($records[Record::TYPE_CNAME])) {
            $type = Record::TYPE_CNAME;
        }

        $answers = [];
        foreach ($records[$type] ?? [] as $rdata) {
            $answers[] = new Record($question->name, $type, Record::CLASS_IN, 60, $rdata);
        }

        return Message::response(
            $query->header,
            self::RCODE_NOERROR,
            $query->questions,
            $answers,
            authoritative: $this->authoritative,
            recursionAvailable: !$this->authoritative,
        );
    }
}

final class DNSTest extends TestCase
{
    /**
     * Loopback addresses the fakes listen on. A recursive resolver, a second one, and two
     * authoritative nameservers. The validator treats reserved space as trusted here because
     * the resolver itself lives in it.
     */
    private const string RESOLVER = '127.0.0.1';
    private const string OTHER_RESOLVER = '127.0.0.4';
    private const string NS1 = '127.0.0.2';
    private const string NS2 = '127.0.0.3';

    /**
     * @var array<FakeNameserver>
     */
    private array $fakes = [];

    protected function tearDown(): void
    {
        foreach ($this->fakes as $fake) {
            $fake->stop();
        }
        $this->fakes = [];
    }

    /**
     * @param array<string, array<int, array<string>>> $zone
     */
    private function serve(string $ip, array $zone, bool $authoritative): void
    {
        $fake = new FakeNameserver($ip, $zone, $authoritative);

        try {
            $fake->start();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage() . ' (port 53 on loopback needs root, as in the CI container)');
        }

        $this->fakes[] = $fake;
    }

    public function testSingleDNSServer(): void
    {
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8']);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertSame('string', $validator->getType());
    }

    public function testMultipleDNSServers(): void
    {
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertSame('string', $validator->getType());
    }

    public function testValidationFailure(): void
    {
        $validator = new DNS('invalid-target.example.com', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

        $result = $validator->isValid('nonexistent-domain-' . \uniqid() . '.com');

        $this->assertEquals(false, $result);
        $this->assertNotEmpty($validator->getDescription());
    }

    /**
     * The production incident: the zone has the record, the resolver still serves the
     * "does not exist" it cached before the record was added.
     */
    public function testRecordOnZoneVerifiesWhileResolverCachesItsAbsence(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: true);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER]);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    /**
     * The reverse: the owner removed the record, the resolver still serves it. The zone decides.
     */
    public function testRecordRemovedFromZoneFailsWhileResolverStillServesIt(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
        ], authoritative: true);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER]);

        $this->assertFalse($validator->isValid('app.example.com'));
        $this->assertSame(
            'DNS verification failed with resolver ns1.example.com (authoritative). Domain app.example.com is missing CNAME record.',
            $validator->getDescription()
        );
    }

    public function testWrongRecordIsDescribedFromTheZone(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'app.example.com' => [Record::TYPE_CNAME => ['other.example.net']],
        ], authoritative: true);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER]);

        $this->assertFalse($validator->isValid('app.example.com'));
        $this->assertSame(
            "DNS verification failed with resolver ns1.example.com (authoritative). Domain app.example.com has incorrect CNAME value 'other.example.net'.",
            $validator->getDescription()
        );
    }

    /**
     * A server that does not answer authoritatively (a parent handing out a referral for a
     * delegation the resolver had not seen yet) settles nothing; the resolver decides.
     */
    public function testNonAuthoritativeAnswerLeavesTheResolverToDecide(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: false);
        $this->serve(self::NS1, [], authoritative: false);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER]);

        $this->assertTrue($validator->isValid('app.example.com'));

        $this->assertFalse($validator->isValid('gone.example.com'));
        $this->assertSame(
            'DNS verification failed with resolver 127.0.0.1. Domain gone.example.com is missing CNAME record.',
            $validator->getDescription()
        );
    }

    /**
     * The name is an alias whose target lies outside the zone, so the nameserver cannot
     * produce the A record itself; the resolver, which followed the chain, decides.
     */
    public function testAliasLeavingTheZoneIsFollowedThroughTheResolver(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
            'app.example.com' => [Record::TYPE_A => ['203.0.0.1']],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'app.example.com' => [Record::TYPE_CNAME => ['alias.example.net']],
        ], authoritative: true);

        $validator = new DNS('203.0.0.1', Record::TYPE_A, [self::RESOLVER]);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    /**
     * A delegated subdomain is asked on its own nameserver, not on the apex's, which knows
     * nothing about the record.
     */
    public function testDelegatedSubdomainIsAskedOnItsOwnNameserver(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'sub.example.com' => [Record::TYPE_NS => ['ns2.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
            'ns2.example.com' => [Record::TYPE_A => [self::NS2]],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
        ], authoritative: true);
        $this->serve(self::NS2, [
            'app.sub.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: true);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER]);

        $this->assertTrue($validator->isValid('app.sub.example.com'));
    }

    /**
     * The first resolver names the zone's nameserver but cannot say where it is; the second
     * resolver can, and the zone still gets asked.
     */
    public function testDiscoveryMovesOnToTheNextResolver(): void
    {
        $this->serve(self::RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
        ], authoritative: false);
        $this->serve(self::OTHER_RESOLVER, [
            'example.com' => [Record::TYPE_NS => ['ns1.example.com']],
            'ns1.example.com' => [Record::TYPE_A => [self::NS1]],
        ], authoritative: false);
        $this->serve(self::NS1, [
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: true);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER, self::OTHER_RESOLVER]);

        $this->assertTrue($validator->isValid('app.example.com'));
    }

    /**
     * With no nameservers to ask, one resolver finding the record is enough; a resolver that
     * has never heard of the name no longer vetoes one that has.
     */
    public function testAnyOneResolverVerifiesWhenNoNameserverIsKnown(): void
    {
        $this->serve(self::RESOLVER, [], authoritative: false);
        $this->serve(self::OTHER_RESOLVER, [
            'app.example.com' => [Record::TYPE_CNAME => ['appwrite.network']],
        ], authoritative: false);

        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, [self::RESOLVER, self::OTHER_RESOLVER]);

        $this->assertTrue($validator->isValid('app.example.com'));

        $this->assertFalse($validator->isValid('gone.example.com'));
        $this->assertSame(
            'DNS verification failed with resolver 127.0.0.1. Domain gone.example.com is missing CNAME record.',
            $validator->getDescription()
        );
    }

    public function testNoResolverConfiguredFallsBackToGoogle(): void
    {
        $validator = new DNS('appwrite.network', Record::TYPE_CNAME, []);

        $this->assertFalse($validator->isValid('nonexistent-domain-' . \uniqid() . '.com'));
        $this->assertStringContainsString('8.8.8.8', $validator->getDescription());
    }

    /**
     * Against the test resolver (tests/resources/dns), which serves webapp.com with an NS
     * record pointing back at itself: the nameserver is discovered over the wire and its
     * answer, not the resolver's, describes a failure.
     */
    public function testFixtureNameserverIsDiscoveredAndAsked(): void
    {
        $validator = new DNS('cname.localhost', Record::TYPE_CNAME, ['172.16.238.100']);

        $this->assertTrue($validator->isValid('stage.webapp.com'));

        $this->assertFalse($validator->isValid('stage-wrong-cname.webapp.com'));
        $this->assertSame(
            "DNS verification failed with resolver ns.webapp.com (authoritative). Domain stage-wrong-cname.webapp.com has incorrect CNAME value 'cname-wrong.localhost'.",
            $validator->getDescription()
        );
    }

    /**
     * wrong-a-webapp.com has no NS record at the test resolver, so only the resolver is asked.
     */
    public function testFixtureZoneWithoutNameserversIsCheckedOnTheResolverAlone(): void
    {
        $validator = new DNS('203.0.0.1', Record::TYPE_A, ['172.16.238.100']);

        $this->assertFalse($validator->isValid('wrong-a-webapp.com'));
        $this->assertSame(
            "DNS verification failed with resolver 172.16.238.100. Domain wrong-a-webapp.com has incorrect A value '203.0.0.5'.",
            $validator->getDescription()
        );
    }

    /**
     * A public resolver that has never heard of the fixture name does not veto the zone's answer.
     */
    public function testFixtureVerifiesDespiteAnotherResolverFailing(): void
    {
        $validator = new DNS('cname.localhost', Record::TYPE_CNAME, ['172.16.238.100', '8.8.8.8']);

        $this->assertTrue($validator->isValid('stage.webapp.com'));
        $this->assertFalse($validator->isValid('stage-wrong-cname.webapp.com'));
    }
}
