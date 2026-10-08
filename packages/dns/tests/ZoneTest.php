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

    public function testConstructorDropsDuplicateRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $first = new Record('example.com', Record::TYPE_A, ttl: 3600, rdata: '192.0.2.1');
        $duplicate = new Record('example.com', Record::TYPE_A, ttl: 300, rdata: '192.0.2.1');
        $sibling = new Record('example.com', Record::TYPE_A, ttl: 3600, rdata: '192.0.2.2');
        $txt = new Record('example.com', Record::TYPE_TXT, ttl: 3600, rdata: '192.0.2.1');
        $www = new Record('www.example.com', Record::TYPE_A, ttl: 3600, rdata: '192.0.2.1');

        $zone = new Zone('example.com', [$first, $duplicate, $sibling, $txt, $www], $soa);

        $this->assertSame([$first, $sibling, $txt, $www], $zone->records);
    }

    public function testConstructorKeepsMxAndSrvRecordsDifferingOutsideRdata(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('example.com', Record::TYPE_MX, ttl: 3600, rdata: 'mail.example.com', priority: 10),
            new Record('example.com', Record::TYPE_MX, ttl: 3600, rdata: 'mail.example.com', priority: 20),
            new Record('_sip._tcp.example.com', Record::TYPE_SRV, ttl: 3600, rdata: 'sip.example.com', priority: 10, weight: 5, port: 5060),
            new Record('_sip._tcp.example.com', Record::TYPE_SRV, ttl: 3600, rdata: 'sip.example.com', priority: 10, weight: 5, port: 5061),
        ];

        $zone = new Zone('example.com', $records, $soa);

        $this->assertCount(4, $zone->records);
    }
}
