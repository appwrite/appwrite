<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS;

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
}
