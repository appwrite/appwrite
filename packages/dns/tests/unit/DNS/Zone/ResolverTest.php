<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Header;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\Resolver;

final class ResolverTest extends TestCase
{
    public function testLookupReturnsFormerrWhenQueryHasNoQuestion(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $zone = new Zone('example.com', [], $soa);

        $header = new Header(
            id: 42,
            isResponse: false,
            opcode: 0,
            authoritative: false,
            truncated: false,
            recursionDesired: true,
            recursionAvailable: false,
            responseCode: 0,
            questionCount: 0,
            answerCount: 0,
            authorityCount: 0,
            additionalCount: 0,
        );
        $query = new Message($header, []);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_FORMERR, $response->header->responseCode);
        $this->assertSame([], $response->questions);
        $this->assertTrue($response->header->authoritative);
    }

    public function testLookupReturnsExactTypeMatch(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $record = new Record('www.example.com', Record::TYPE_A, ttl: 300, rdata: '1.2.3.4');
        $zone = new Zone('example.com', [$record], $soa);

        $question = new Question('www.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertTrue($response->header->authoritative);
        $this->assertCount(1, $response->answers);
        $this->assertSame($record, $response->answers[0]);
        $this->assertFalse($response->header->recursionAvailable);
    }

    public function testLookupReturnsCnameWhenExactTypeMissing(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $cname = new Record('alias.example.com', Record::TYPE_CNAME, ttl: 1800, rdata: 'target.example.com');
        $zone = new Zone('example.com', [$cname], $soa);

        $question = new Question('alias.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame($cname, $response->answers[0]);
        $this->assertTrue($response->header->authoritative);
    }

    public function testLookupExactMatchNodataReturnsSoa(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $txtRecord = new Record('www.example.com', Record::TYPE_TXT, ttl: 600, rdata: '"hello"');
        $zone = new Zone('example.com', [$txtRecord], $soa);

        $question = new Question('www.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertSame([$question], $response->questions);
        $this->assertSame([], $response->answers);
        $this->assertCount(1, $response->authority);
        $this->assertSame($soa, $response->authority[0]);
        $this->assertTrue($response->header->authoritative);
    }

    public function testLookupReturnsNxDomainWithSoaWhenNameMissing(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $record = new Record('www.example.com', Record::TYPE_A, ttl: 300, rdata: '1.2.3.4');
        $zone = new Zone('example.com', [$record], $soa);

        $question = new Question('missing.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NXDOMAIN, $response->header->responseCode);
        $this->assertSame([], $response->answers);
        $this->assertCount(1, $response->authority);
        $this->assertSame($soa, $response->authority[0]);
    }

    public function testLookupSynthesizesWildcardAnswer(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $wildcard = new Record('*.example.com', Record::TYPE_A, ttl: 60, rdata: '1.1.1.1');
        $zone = new Zone('example.com', [$wildcard], $soa);

        $question = new Question('host.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame('host.example.com', $response->answers[0]->name);
        $this->assertSame($wildcard->rdata, $response->answers[0]->rdata);
        $this->assertSame($wildcard->ttl, $response->answers[0]->ttl);
    }

    public function testLookupSynthesizesWildcardCname(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $wildcard = new Record('*.example.com', Record::TYPE_CNAME, ttl: 600, rdata: 'target.example.com');
        $zone = new Zone('example.com', [$wildcard], $soa);

        $question = new Question('beta.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $answer = $response->answers[0];
        $this->assertSame('beta.example.com', $answer->name);
        $this->assertSame(Record::TYPE_CNAME, $answer->type);
        $this->assertSame($wildcard->rdata, $answer->rdata);
    }

    /**
     * Test that multiple A records for the same name are all returned.
     * Note: RRSet order is randomized for load balancing (RFC 2181 Section 5).
     */
    public function testLookupReturnsMultipleExactTypeRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $aPrimary = new Record('www.example.com', Record::TYPE_A, ttl: 300, rdata: '203.0.113.10');
        $aSecondary = new Record('www.example.com', Record::TYPE_A, ttl: 180, rdata: '203.0.113.20');
        $zone = new Zone('example.com', [$aPrimary, $aSecondary], $soa);

        $question = new Question('www.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(2, $response->answers);

        // Check both records are present (order may vary due to RRSet randomization)
        $rdatas = array_map(fn(\Utopia\DNS\Message\Record $r): string => $r->rdata, $response->answers);
        $this->assertContains('203.0.113.10', $rdatas);
        $this->assertContains('203.0.113.20', $rdatas);

        // Verify all records have correct type and name
        foreach ($response->answers as $answer) {
            $this->assertSame('www.example.com', $answer->name);
            $this->assertSame(Record::TYPE_A, $answer->type);
        }
    }

    /**
     * Test that wildcard MX records preserve priority after synthesis.
     * Note: RRSet order is randomized for load balancing (RFC 2181 Section 5),
     * so we check for record presence without assuming specific order.
     */
    public function testLookupSynthesizesWildcardMxPreservingPriority(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $mxPrimary = new Record(
            '*.example.com',
            Record::TYPE_MX,
            ttl: 3600,
            rdata: 'mail1.example.com',
            priority: 10,
        );
        $mxSecondary = new Record(
            '*.example.com',
            Record::TYPE_MX,
            ttl: 3600,
            rdata: 'mail2.example.com',
            priority: 20,
        );
        $zone = new Zone('example.com', [$mxPrimary, $mxSecondary], $soa);

        $question = new Question('api.example.com', Record::TYPE_MX);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(2, $response->answers);

        // Extract priorities and rdata for order-independent comparison
        $answers = $response->answers;
        $priorities = array_map(fn(\Utopia\DNS\Message\Record $r): ?int => $r->priority, $answers);
        $rdatas = array_map(fn(\Utopia\DNS\Message\Record $r): string => $r->rdata, $answers);

        // Both records should have synthesized name
        foreach ($answers as $answer) {
            $this->assertSame('api.example.com', $answer->name);
            $this->assertSame(Record::TYPE_MX, $answer->type);
        }

        // Check both expected MX records are present (order may vary due to RRSet randomization)
        $this->assertContains(10, $priorities);
        $this->assertContains(20, $priorities);
        $this->assertContains('mail1.example.com', $rdatas);
        $this->assertContains('mail2.example.com', $rdatas);
    }

    public function testLookupReturnsReferralForDelegatedSubdomain(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $delegation = new Record(
            'delegated.example.com',
            Record::TYPE_NS,
            ttl: 86400,
            rdata: 'ns1.delegated.example.com',
        );
        $zone = new Zone('example.com', [$delegation], $soa);

        $question = new Question('delegated.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertSame([$question], $response->questions);
        $this->assertSame([], $response->answers);
        $this->assertCount(1, $response->authority);
        $this->assertSame($delegation, $response->authority[0]);
        $this->assertFalse($response->header->authoritative);
    }

    public function testLookupWildcardNodataReturnsSoa(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $wildcard = new Record('*.example.com', Record::TYPE_TXT, ttl: 120, rdata: '"v=spf1 ~all"');
        $zone = new Zone('example.com', [$wildcard], $soa);

        $question = new Question('svc.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertSame([$question], $response->questions);
        $this->assertSame([], $response->answers);
        $this->assertCount(1, $response->authority);
        $this->assertSame($soa, $response->authority[0]);
        $this->assertTrue($response->header->authoritative);
    }

    public function testLookupPrefersExactMatchOverWildcard(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $exact = new Record('www.example.com', Record::TYPE_A, ttl: 300, rdata: '2.2.2.2');
        $wildcard = new Record('*.example.com', Record::TYPE_A, ttl: 60, rdata: '3.3.3.3');
        $zone = new Zone('example.com', [$exact, $wildcard], $soa);

        $question = new Question('www.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertCount(1, $response->answers);
        $this->assertSame($exact, $response->answers[0]);
    }

    public function testLookupUsesClosestEnclosingWildcard(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $broadWildcard = new Record('*.example.com', Record::TYPE_A, ttl: 60, rdata: '1.1.1.1');
        $specificWildcard = new Record('*.sub.example.com', Record::TYPE_A, ttl: 60, rdata: '2.2.2.2');
        $zone = new Zone('example.com', [$broadWildcard, $specificWildcard], $soa);

        $question = new Question('host.sub.example.com', Record::TYPE_A);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $answer = $response->answers[0];
        $this->assertSame('host.sub.example.com', $answer->name);
        $this->assertSame('2.2.2.2', $answer->rdata);
        $this->assertSame(Record::TYPE_A, $answer->type);
    }

    public function testLookupResolvesWildcardCnameQuery(): void
    {
        $soa = new Record(
            'test-dns.appwrite.org',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1-stage.appwrite.zone team.appwrite.io 1 3600 600 86400 300',
        );

        $zone = new Zone('test-dns.appwrite.org', [
            new Record('test-dns.appwrite.org', Record::TYPE_NS, ttl: 3600, rdata: 'ns1.example.org'),
            new Record('*.wildcard.test-dns.appwrite.org', Record::TYPE_CNAME, ttl: 3600, rdata: 'stage.appwrite.network'),
        ], $soa);

        $question = new Question('baz.wildcard.test-dns.appwrite.org', Record::TYPE_CNAME);
        $query = Message::query($question);

        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertTrue($response->header->authoritative);
        $this->assertCount(1, $response->answers);
        $answer = $response->answers[0];
        $this->assertSame('baz.wildcard.test-dns.appwrite.org', $answer->name);
        $this->assertSame(Record::TYPE_CNAME, $answer->type);
        $this->assertSame('stage.appwrite.network', $answer->rdata);
        $this->assertSame(3600, $answer->ttl);
    }

    public function testIsAuthoritativeDetectsDelegation(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $nsRecord = new Record('delegated.example.com', Record::TYPE_NS, ttl: 3600, rdata: 'ns1.delegated.example.com');
        $zone = new Zone('example.com', [$nsRecord], $soa);

        $this->assertFalse($zone->isAuthoritative('delegated.example.com'));
        $this->assertTrue($zone->isAuthoritative('www.example.com'));
    }

    public function testLookupReturnsApexARecord(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300',
        );
        $nsRecord1 = new Record('example.com', Record::TYPE_NS, ttl: 3600, rdata: 'ns1-stage.appwrite.zone');
        $nsRecord2 = new Record('example.com', Record::TYPE_NS, ttl: 3600, rdata: 'ns2-stage.appwrite.zone');
        $aRecord = new Record('example.com', Record::TYPE_A, ttl: 3600, rdata: '1.1.1.1');
        $aaaaRecord = new Record('example.com', Record::TYPE_AAAA, ttl: 3600, rdata: '2606:4700::1111');
        $wildcardCname = new Record('*.example.com', Record::TYPE_CNAME, ttl: 3600, rdata: 'stage.appwrite.network');

        $zone = new Zone('example.com', [$nsRecord1, $nsRecord2, $aRecord, $aaaaRecord, $wildcardCname], $soa);

        $question = new Question('example.com', Record::TYPE_A);
        $query = Message::query($question);
        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame($aRecord, $response->answers[0]);
        $this->assertTrue($response->header->authoritative);
    }

    public function testLookupReturnsApexAAAARecord(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300',
        );
        $aRecord = new Record('example.com', Record::TYPE_A, ttl: 3600, rdata: '1.1.1.1');
        $aaaaRecord = new Record('example.com', Record::TYPE_AAAA, ttl: 3600, rdata: '2606:4700::1111');

        $zone = new Zone('example.com', [$aRecord, $aaaaRecord], $soa);

        $question = new Question('example.com', Record::TYPE_AAAA);
        $query = Message::query($question);
        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame($aaaaRecord, $response->answers[0]);
    }

    public function testLookupReturnsSoaAnswerForApexSoaQueryWithRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300',
        );
        $aRecord = new Record('example.com', Record::TYPE_A, ttl: 3600, rdata: '1.1.1.1');
        $zone = new Zone('example.com', [$aRecord], $soa);

        $question = new Question('example.com', Record::TYPE_SOA);
        $query = Message::query($question);
        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame($soa, $response->answers[0]);
        $this->assertTrue($response->header->authoritative);
        $this->assertFalse($response->header->recursionAvailable);
    }

    public function testLookupReturnsSoaAnswerForApexSoaQueryWithNoRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300',
        );
        $zone = new Zone('example.com', [], $soa);

        $question = new Question('example.com', Record::TYPE_SOA);
        $query = Message::query($question);
        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame($soa, $response->answers[0]);
        $this->assertTrue($response->header->authoritative);
        $this->assertFalse($response->header->recursionAvailable);
    }

    public function testLookupReturnsSoaInAuthorityForApexNonNSQuery(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 300,
            rdata: 'ns1.appwrite.zone. team@appwrite.io. 1761705275 3600 600 86400 300',
        );
        $nsRecord1 = new Record('example.com', Record::TYPE_NS, ttl: 3600, rdata: 'ns1-stage.appwrite.zone');
        $nsRecord2 = new Record('example.com', Record::TYPE_NS, ttl: 3600, rdata: 'ns2-stage.appwrite.zone');

        $zone = new Zone('example.com', [$nsRecord1, $nsRecord2], $soa);

        // Query for A record that doesn't exist
        $question = new Question('example.com', Record::TYPE_A);
        $query = Message::query($question);
        $response = Resolver::lookup($query, $zone);

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(0, $response->answers);
        $this->assertCount(1, $response->authority);
        $this->assertSame($soa, $response->authority[0]);
    }
}
