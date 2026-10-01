<?php

declare(strict_types=1);

namespace Utopia\DNS\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;

final class ZoneTest extends TestCase
{
    public function testConstructorRejectsNonSoaRecord(): void
    {
        $soa = new Record('example.com', Record::TYPE_A);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SOA parameter must be a Record with TYPE_SOA');

        new Zone('example.com', [], $soa);
    }

    public function testConstructorRequiresMatchingSoaName(): void
    {
        $soa = new Record(
            'other.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.other.com hostmaster.other.com 1 7200 3600 1209600 300',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SOA record name must match zone name: expected 'example.com', got 'other.com'");

        new Zone('example.com', [], $soa);
    }

    public function testConstructorRejectsSoaRecordsInZoneData(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('example.com', Record::TYPE_SOA),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SOA records should be passed as the $soa parameter, not in $records');

        new Zone('example.com', $records, $soa);
    }

    public function testConstructorRejectsOutOfZoneRecord(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('other.com', Record::TYPE_A, ttl: 300, rdata: '1.1.1.1'),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Record name 'other.com' does not belong to zone 'example.com'");

        new Zone('example.com', $records, $soa);
    }

    public function testConstructorRejectsOutOfZoneWildcardRecord(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('*.other.com', Record::TYPE_A, ttl: 300, rdata: '1.1.1.1'),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Record name '*.other.com' does not belong to zone 'example.com'");

        new Zone('example.com', $records, $soa);
    }

    public function testConstructorAcceptsNestedWildcardRecord(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('*.api.example.com', Record::TYPE_A, ttl: 120, rdata: '203.0.113.10'),
            new Record('origin.api.example.com', Record::TYPE_A, ttl: 120, rdata: '203.0.113.20'),
        ];

        $zone = new Zone('example.com', $records, $soa);

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertCount(2, $zone->records);
    }

    public function testConstructorAcceptsTemplateRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('api.example.com', Record::TYPE_A, ttl: 120, rdata: 'a.a.a.a'),
            new Record('api.example.com', Record::TYPE_AAAA, ttl: 120, rdata: 'b:b::b:b:b'),
        ];

        $zone = new Zone('example.com', $records, $soa);

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertCount(2, $zone->records);
    }

    public function testLockedAndUnlockedIdenticalApexCaaPublishOnce(): void
    {
        // The managed record is the locked "@" CAA. An unlocked copy stored under
        // the apex FQDN absolutizes to the same name with the same rdata, so the
        // zone would otherwise answer one record twice.
        $managed = new Record('caudit.com', Record::TYPE_CAA, ttl: 3600, rdata: '0 issue "certainly.com"');
        $copy = new Record('caudit.com', Record::TYPE_CAA, ttl: 300, rdata: '0 issue "certainly.com"');
        $otherIssuer = new Record('caudit.com', Record::TYPE_CAA, ttl: 3600, rdata: '0 issue "letsencrypt.org"');
        $www = new Record('www.caudit.com', Record::TYPE_CAA, ttl: 3600, rdata: '0 issue "certainly.com"');

        $zone = new Zone('caudit.com', [$managed, $copy, $otherIssuer, $www], $this->soa('caudit.com'));

        $apex = array_values(array_filter(
            $zone->records,
            static fn (Record $record): bool => $record->name === 'caudit.com' && $record->type === Record::TYPE_CAA,
        ));

        $this->assertCount(2, $apex);
        $this->assertSame($managed, $apex[0]);
        $this->assertSame('0 issue "letsencrypt.org"', $apex[1]->rdata);
        $this->assertCount(3, $zone->records);
    }

    public function testAnyRecordTypePublishesOnce(): void
    {
        // An RRset is a set for every type, not just CAA.
        $first = new Record('caudit.com', Record::TYPE_A, ttl: 3600, rdata: '192.0.2.1');
        $duplicate = new Record('caudit.com', Record::TYPE_A, ttl: 300, rdata: '192.0.2.1');
        $sibling = new Record('caudit.com', Record::TYPE_A, ttl: 3600, rdata: '192.0.2.2');
        $txt = new Record('caudit.com', Record::TYPE_TXT, ttl: 3600, rdata: '192.0.2.1');

        $zone = new Zone('caudit.com', [$first, $duplicate, $sibling, $txt], $this->soa('caudit.com'));

        $this->assertSame([$first, $sibling, $txt], $zone->records);
    }

    public function testRecordsDifferingOnlyOutsideRdataBothPublish(): void
    {
        // MX and SRV carry part of their identity outside rdata, so collapsing on
        // rdata alone would silently drop a valid record.
        $mx10 = new Record('caudit.com', Record::TYPE_MX, ttl: 3600, rdata: 'mail.caudit.com', priority: 10);
        $mx20 = new Record('caudit.com', Record::TYPE_MX, ttl: 3600, rdata: 'mail.caudit.com', priority: 20);
        $srv = new Record('_sip._tcp.caudit.com', Record::TYPE_SRV, ttl: 3600, rdata: 'sip.caudit.com', priority: 10, weight: 5, port: 5060);
        $srvOtherPort = new Record('_sip._tcp.caudit.com', Record::TYPE_SRV, ttl: 3600, rdata: 'sip.caudit.com', priority: 10, weight: 5, port: 5061);

        $zone = new Zone('caudit.com', [$mx10, $mx20, $srv, $srvOtherPort], $this->soa('caudit.com'));

        $this->assertCount(4, $zone->records);
    }

    private function soa(string $name): Record
    {
        return new Record(
            $name,
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: "ns1.{$name} hostmaster.{$name} 1 7200 3600 1209600 300",
        );
    }
}
