<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as BaseDNS;

/**
 * What one server holds for the value: its records, or null when the query fails.
 */
final class ScriptedAnswer extends BaseDNS
{
    /**
     * @param array<string>|null $rdata
     */
    public function __construct(string $target, int $type, string $server, private readonly ?array $rdata)
    {
        parent::__construct($target, $type, $server);
    }

    public function isValid(mixed $value): bool
    {
        $this->value = \strval($value);
        $this->records = [];
        $this->count = 0;
        $this->reason = '';

        if ($this->rdata === null) {
            $this->reason = self::FAILURE_REASON_QUERY;
            return false;
        }

        $this->records = $this->rdata;
        $this->count = \count($this->rdata);

        return \in_array($this->target, $this->rdata, true);
    }
}

/**
 * A DNS validator whose nameservers and per-server answers are given up front.
 */
final class ScriptedDNS extends DNS
{
    /**
     * @param array<string> $resolvers
     * @param array<string, string> $authoritative nameserver hostname => IP
     * @param array<string, array<string>|null> $answers server IP => records it returns, null for a failed query
     */
    public function __construct(
        string $target,
        array $resolvers,
        private readonly array $authoritative,
        private readonly array $answers,
    ) {
        parent::__construct($target, Record::TYPE_CNAME, $resolvers);
    }

    protected function findAuthoritativeServers(string $value): array
    {
        return $this->authoritative;
    }

    protected function createValidator(string $server): BaseDNS
    {
        return new ScriptedAnswer($this->target, $this->type, $server, $this->answers[$server] ?? null);
    }
}

final class DNSTest extends TestCase
{
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
     * The production incident: the record exists in the zone, but the resolver still
     * serves the "does not exist" it cached before the record was added.
     */
    public function testRecordOnNameserverVerifiesWhileResolverCachesItsAbsence(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            ['8.8.8.8'],
            ['ns1.hostcreators.sk' => '198.51.100.1'],
            [
                '8.8.8.8' => [],
                '198.51.100.1' => ['appwrite.network'],
            ],
        );

        $this->assertTrue($validator->isValid('matej-test.rdo1337.eu'));
    }

    public function testMissingEverywhereIsDescribedFromTheZone(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            ['8.8.8.8'],
            ['ns1.hostcreators.sk' => '198.51.100.1'],
            [
                '8.8.8.8' => [],
                '198.51.100.1' => [],
            ],
        );

        $this->assertFalse($validator->isValid('matej-test.rdo1337.eu'));
        $this->assertSame(
            'DNS verification failed with resolver ns1.hostcreators.sk (authoritative). Domain matej-test.rdo1337.eu is missing CNAME record.',
            $validator->getDescription()
        );
    }

    public function testWrongRecordIsDescribedFromTheZone(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            ['8.8.8.8'],
            ['ns1.hostcreators.sk' => '198.51.100.1'],
            [
                '8.8.8.8' => [],
                '198.51.100.1' => ['other.example.net'],
            ],
        );

        $this->assertFalse($validator->isValid('matej-test.rdo1337.eu'));
        $this->assertSame(
            "DNS verification failed with resolver ns1.hostcreators.sk (authoritative). Domain matej-test.rdo1337.eu has incorrect CNAME value 'other.example.net'.",
            $validator->getDescription()
        );
    }

    public function testUnreachableNameserverLeavesTheResolverToDescribe(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            ['8.8.8.8'],
            ['ns1.hostcreators.sk' => '198.51.100.1'],
            [
                '8.8.8.8' => ['other.example.net'],
                '198.51.100.1' => null,
            ],
        );

        $this->assertFalse($validator->isValid('matej-test.rdo1337.eu'));
        $this->assertSame(
            "DNS verification failed with resolver 8.8.8.8. Domain matej-test.rdo1337.eu has incorrect CNAME value 'other.example.net'.",
            $validator->getDescription()
        );
    }

    public function testAnyOneResolverVerifies(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            ['8.8.8.8', '1.1.1.1'],
            [],
            [
                '8.8.8.8' => [],
                '1.1.1.1' => ['appwrite.network'],
            ],
        );

        $this->assertTrue($validator->isValid('matej-test.rdo1337.eu'));
    }

    public function testNoResolverConfiguredFallsBackToGoogle(): void
    {
        $validator = new ScriptedDNS(
            'appwrite.network',
            [],
            [],
            ['8.8.8.8' => ['appwrite.network']],
        );

        $this->assertTrue($validator->isValid('matej-test.rdo1337.eu'));
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
     * A resolver that has never heard of the name (8.8.8.8 for a name that only exists
     * on the test resolver) no longer vetoes one that has.
     */
    public function testFixtureResolverVerifiesDespiteAnotherResolverFailing(): void
    {
        $validator = new DNS('cname.localhost', Record::TYPE_CNAME, ['172.16.238.100', '8.8.8.8']);

        $this->assertTrue($validator->isValid('stage.webapp.com'));
        $this->assertFalse($validator->isValid('stage-wrong-cname.webapp.com'));
    }
}
