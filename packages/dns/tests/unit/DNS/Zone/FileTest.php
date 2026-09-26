<?php

declare(strict_types=1);

namespace Tests\Utopia\DNS\Zone;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Exception\Zone\ImportException;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\File;

final class FileTest extends TestCase
{
    private const string DEFAULT_SOA = '@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800';

    public function testExampleComZoneFile(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../resources/zone-valid-example.com.txt');

        $zone = File::import($content);

        $this->assertSame('example.com', $zone->name);
        $this->assertNotEmpty($zone->records);
    }

    public function testRedHatZoneFile(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../resources/zone-valid-redhat.txt');

        $zone = File::import($content);

        $this->assertSame('example.com', $zone->name);
        $this->assertNotEmpty($zone->records);
    }

    public function testOracle1ZoneFile(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../resources/zone-valid-oracle1.txt');

        $zone = File::import($content, 'example.com');

        $this->assertSame('example.com', $zone->name);
        $this->assertNotEmpty($zone->records);
    }

    public function testOracle2ZoneFile(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../resources/zone-valid-oracle2.txt');

        $zone = File::import($content);

        $this->assertSame('example.com', $zone->name);
        $this->assertNotEmpty($zone->records);
    }

    public function testLocalhostZoneFile(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../resources/zone-valid-localhost.txt');

        $zone = File::import($content);

        $this->assertSame('localhost', $zone->name);
        $this->assertNotEmpty($zone->records);
    }

    public function testImportValidZoneWithDirectives(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
\$TTL 1800
{$soa}

www IN A 192.168.1.10
mail 300 IN MX 10 mail
_sip._tcp 600 IN SRV 5 10 5060 sip
ZONE;

        $zone = File::import($contents);

        $this->assertSame(1800, $zone->soa->ttl);
        $this->assertCount(3, $zone->records);

        $www = $zone->records[0];
        $this->assertSame('www.example.com', $www->name);
        $this->assertSame(1800, $www->ttl);
        $this->assertSame(Record::TYPE_A, $www->type);
        $this->assertSame('192.168.1.10', $www->rdata);

        $mx = $this->findRecord($zone->records, Record::TYPE_MX);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $mx);
        $this->assertSame(300, $mx->ttl);
        $this->assertSame('mail.example.com', $mx->rdata);
        $this->assertSame(10, $mx->priority);

        $srv = $this->findRecord($zone->records, Record::TYPE_SRV);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $srv);
        $this->assertSame('_sip._tcp.example.com', $srv->name);
        $this->assertSame(5, $srv->priority);
        $this->assertSame(10, $srv->weight);
        $this->assertSame(5060, $srv->port);
        $this->assertSame('sip.example.com', $srv->rdata);
    }

    public function testImportFailsWithUnsupportedDirective(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('$INCLUDE directive is not supported');

        File::import(<<<ZONE
\$ORIGIN example.com.
\$INCLUDE other.zone
ZONE);
    }

    public function testImportFailsWithUnknownRecordType(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage("Invalid record type 'BADTYPE' (line 3).");

        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
www 300 IN BADTYPE data
ZONE;

        File::import($contents);
    }

    public function testImportFailsWhenMxPriorityMissing(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('MX requires numeric priority and exchange');

        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
mail 3600 IN MX mail.example.com.
ZONE;

        File::import($contents);
    }

    public function testImportFailsWhenSrvFieldsMissing(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('SRV requires priority, weight, port, target');

        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
_sip._tcp 600 IN SRV 5 10 5060
ZONE;

        File::import($contents);
    }

    public function testImportHandlesBlankLinesAndComments(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}

; comment line

@ 3600 IN A 127.0.0.1
ZONE;

        $zone = File::import($contents);

        $this->assertCount(1, $zone->records);
        $this->assertSame('example.com', $zone->records[0]->name);
    }

    public function testImportAllowsZeroTtl(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
@ 0 IN A 127.0.0.1
ZONE;

        $zone = File::import($contents);

        $this->assertSame(0, $zone->records[0]->ttl);
    }

    public function testImportUsesDefaultOriginWhenDirectiveMissing(): void
    {
        $contents = <<<'ZONE'
@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
www 600 IN A 192.0.2.10
ZONE;

        $zone = File::import($contents, 'example.com');

        $this->assertSame('example.com', $zone->name);
        $this->assertSame('www.example.com', $zone->records[0]->name);
    }

    public function testImportAllowsEmailAddressSoaRnameToEncode(): void
    {
        $contents = <<<'ZONE'
@ IN SOA ns1.example.com. first.last@example.com. 2025011801 7200 3600 1209600 1800
www 600 IN A 192.0.2.10
ZONE;

        $zone = File::import($contents, 'example.com');

        $encoded = $zone->soa->encode();

        $this->assertStringContainsString("\x0Afirst.last\x07example\x03com\x00", $encoded);
    }

    public function testImportFailsWhenSoaDataMissing(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM');

        File::import('@ IN SOA ns1.example.com. admin.example.com.', 'example.com');
    }

    public function testImportWithRelativeNamesExpandsToZone(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
www     IN  A   192.0.2.10
mail    IN  MX  10 mail
alias   IN  CNAME   www
ZONE;

        $zone = File::import($contents);

        $this->assertSame('example.com', $zone->soa->name);

        $mx = $this->findRecord($zone->records, Record::TYPE_MX);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $mx);
        $this->assertSame('mail.example.com', $mx->rdata);

        $cname = $this->findRecord($zone->records, Record::TYPE_CNAME);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $cname);
        $this->assertSame('www.example.com', $cname->rdata);
    }

    public function testImportHandlesClassBeforeTtl(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
@ IN 3600 A 192.0.2.10
ZONE;

        $zone = File::import($contents);

        $record = $zone->records[0];
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(3600, $record->ttl);
    }

    public function testImportDefaultsClassToIn(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
@ 600 A 192.0.2.11
ZONE;

        $zone = File::import($contents);

        $record = $zone->records[0];
        $this->assertSame(Record::CLASS_IN, $record->class);
    }

    public function testImportCollapsesParenthesizedTxtRecords(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
multiline 600 IN TXT (
    "foo"
    "bar"
)
ZONE;

        $zone = File::import($contents);
        $txt = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $txt);
        $this->assertSame('foobar', $txt->rdata);
    }

    public function testImportDecodesDecimalEscapesInTxt(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
escaped 600 IN TXT "foo\\010bar"
ZONE;

        $zone = File::import($contents);
        $txt = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $txt);
        $this->assertSame('foo' . \chr(10) . 'bar', $txt->rdata);
    }

    public function testImportIgnoresUnknownDirective(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
\$FOO bar
www IN A 192.168.1.10
ZONE;

        $zone = File::import($contents);

        $this->assertCount(1, $zone->records);
        $this->assertSame('www.example.com', $zone->records[0]->name);
    }

    public function testImportTxtWithSpecialChars(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
@ 3600 IN TXT "v=DMARC1; p=none; rua=mailto:jon@snow.got; ruf=mailto:jon@snow.got; fo=1;"
ZONE;

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('v=DMARC1; p=none; rua=mailto:jon@snow.got; ruf=mailto:jon@snow.got; fo=1;', $record->rdata);
    }

    public function testExportTxtWithSpecialChars(): void
    {
        $zone = new Zone(
            'example.com',
            [
                new Record('example.com', Record::TYPE_TXT, Record::CLASS_IN, 3600, 'v=DMARC1; text="quoted"; backslash=\\'),
            ],
            new Record('example.com', Record::TYPE_SOA, Record::CLASS_IN, 3600, 'ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800'),
        );

        $expected = "\$ORIGIN example.com.\n\$TTL 3600\n\n@\t3600\tIN\tSOA\tns1 admin (\n\t\t\t\t2025011801\t; serial\n\t\t\t\t7200\t; refresh\n\t\t\t\t3600\t; retry\n\t\t\t\t1209600\t; expire\n\t\t\t\t1800 )\t; minimum\n\n@\t3600\tIN\tTXT\t\"v=DMARC1; text=\\\"quoted\\\"; backslash=\\\\\"\n";

        $exported = File::export($zone, includeComments: false);
        $this->assertSame($expected, $exported);

        $roundTrip = File::import($exported);
        $roundTripTxt = $this->findRecord($roundTrip->records, Record::TYPE_TXT);

        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $roundTripTxt);
        $this->assertSame($zone->records[0]->rdata, $roundTripTxt->rdata);
    }

    public function testImportExportRoundTrip(): void
    {
        $contents = <<<ZONE
\$ORIGIN example.com.
\$TTL 1200
@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
www IN A 192.168.1.10
mail 600 IN MX 10 mail
ZONE;

        $zone = File::import($contents);
        $this->assertCount(2, $zone->records);

        $exported = File::export($zone, includeComments: false);
        $roundTrip = File::import($exported);

        $this->assertSame($zone->name, $roundTrip->name);
        $this->assertSame($zone->soa->rdata, $roundTrip->soa->rdata);
        $this->assertCount(\count($zone->records), $roundTrip->records);
        $this->assertSame($zone->records[1]->rdata, $roundTrip->records[1]->rdata);
    }

    public function testExportBasicZone(): void
    {
        $zone = new Zone(
            'example.com',
            [
                new Record('example.com', Record::TYPE_NS, Record::CLASS_IN, 3600, 'ns1.example.com'),
                new Record('www.example.com', Record::TYPE_A, Record::CLASS_IN, 1800, '192.168.1.10'),
                new Record('mail.example.com', Record::TYPE_MX, Record::CLASS_IN, 300, 'mail.example.com', priority: 10),
            ],
            new Record('example.com', Record::TYPE_SOA, Record::CLASS_IN, 1800, 'ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800'),
        );

        $expected = "\$ORIGIN example.com.\n\$TTL 1800\n\n@\t1800\tIN\tSOA\tns1 admin (\n\t\t\t\t2025011801\t; serial\n\t\t\t\t7200\t; refresh\n\t\t\t\t3600\t; retry\n\t\t\t\t1209600\t; expire\n\t\t\t\t1800 )\t; minimum\n\n@\t3600\tIN\tNS\tns1\n\nwww\t1800\tIN\tA\t192.168.1.10\n\nmail\t300\tIN\tMX\t10 mail\n";

        $output = File::export($zone, includeComments: false);
        $this->assertSame($expected, $output);

        $roundTrip = File::import($output);
        $this->assertCount(3, $roundTrip->records);
        $this->assertSame('mail.example.com', $roundTrip->records[2]->name);
    }

    public function testExportAndImportKeepZeroMxPriority(): void
    {
        // The zone file is the only thing a resolver reads. A priority of 0 written as an empty
        // field does not just lose the priority: the line stops parsing, and an import failure
        // takes every other record in the zone with it.
        $zone = new Zone(
            'example.com',
            [
                new Record('mail.example.com', Record::TYPE_MX, Record::CLASS_IN, 300, 'mail.example.com', priority: 0),
            ],
            new Record('example.com', Record::TYPE_SOA, Record::CLASS_IN, 1800, 'ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800'),
        );

        $exported = File::export($zone, includeComments: false);
        $this->assertStringContainsString("mail\t300\tIN\tMX\t0 mail\n", $exported);

        $roundTrip = File::import($exported);
        $record = $this->findRecord($roundTrip->records, Record::TYPE_MX);

        $this->assertInstanceOf(Record::class, $record);
        $this->assertSame(0, $record->priority);
        $this->assertSame('mail.example.com', $record->rdata);
    }

    public function testExportAndImportKeepZeroSrvNumerics(): void
    {
        $zone = new Zone(
            'example.com',
            [
                new Record('_sip._tcp.example.com', Record::TYPE_SRV, Record::CLASS_IN, 300, 'sip.example.com', priority: 0, weight: 0, port: 0),
            ],
            new Record('example.com', Record::TYPE_SOA, Record::CLASS_IN, 1800, 'ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800'),
        );

        $exported = File::export($zone, includeComments: false);
        $this->assertStringContainsString("_sip._tcp\t300\tIN\tSRV\t0 0 0 sip\n", $exported);

        $roundTrip = File::import($exported);
        $record = $this->findRecord($roundTrip->records, Record::TYPE_SRV);

        $this->assertInstanceOf(Record::class, $record);
        $this->assertSame(0, $record->priority);
        $this->assertSame(0, $record->weight);
        $this->assertSame(0, $record->port);
        $this->assertSame('sip.example.com', $record->rdata);
    }

    public function testImportSupportsPtrRecords(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
1 3600 IN PTR host.example.com.
ZONE;

        $zone = File::import($contents);
        $ptr = $this->findRecord($zone->records, Record::TYPE_PTR);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $ptr);
        $this->assertSame('1.example.com', $ptr->name);
        $this->assertSame('host.example.com', $ptr->rdata);
    }

    public function testImportSupportsMultilineSoa(): void
    {
        $contents = <<<'ZONE'
$ORIGIN example.com.
@ 3600 IN SOA (
    ns1.example.com.
    admin.example.com.
    2025011801
    7200
    3600
    1209600
    1800
)
www 1800 IN A 192.0.2.10
ZONE;

        $zone = File::import($contents);

        $this->assertSame('ns1.example.com admin.example.com 2025011801 7200 3600 1209600 1800', $zone->soa->rdata);
        $this->assertSame('www.example.com', $zone->records[0]->name);
    }

    public function testImportHandlesMultipleOrigins(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
www IN A 192.0.2.10
\$ORIGIN sub.example.com.
@ 600 IN AAAA 2001:db8::1
\$ORIGIN example.com.
api IN CNAME www
ZONE;

        $zone = File::import($contents);

        $this->assertSame('www.example.com', $zone->records[0]->name);
        $this->assertSame('sub.example.com', $zone->records[1]->name);
        $this->assertSame('api.example.com', $zone->records[2]->name);
        $this->assertSame('www.example.com', $zone->records[2]->rdata);
    }

    public function testImportAllowsOwnerOmissionWithPreviousOwner(): void
    {
        $soa = self::DEFAULT_SOA;
        $contents = <<<ZONE
\$ORIGIN example.com.
{$soa}
www IN A 192.0.2.10
    IN AAAA 2001:db8::1
ZONE;

        $zone = File::import($contents);

        $this->assertSame('www.example.com', $zone->records[0]->name);
        $this->assertSame('www.example.com', $zone->records[1]->name);
        $this->assertSame(Record::TYPE_AAAA, $zone->records[1]->type);
    }

    public function testImportTxtWithEscapedSemicolon(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN TXT "foo\;bar"
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('foo;bar', $record->rdata);
    }

    public function testImportTxtWithSemicolonInQuotes(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN TXT "not a comment; still text"
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('not a comment; still text', $record->rdata);
    }

    public function testImportExportRoundTripForAaaa(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
www 600 IN AAAA 2001:db8::1
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $this->assertSame(Record::TYPE_AAAA, $zone->records[0]->type);

        $exported = File::export($zone, includeComments: false);
        $roundTrip = File::import($exported);

        $this->assertSame($zone->records[0]->rdata, $roundTrip->records[0]->rdata);
    }

    public function testCanExportZoneWithTemplateRecords(): void
    {
        $soa = new Record(
            'example.com',
            Record::TYPE_SOA,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300',
        );
        $records = [
            new Record('api.example.com', Record::TYPE_A, ttl: 120, rdata: 'a.a.a.a'),
            new Record('api.example.com', Record::TYPE_A, ttl: 120, rdata: 'b:b::b:b:b'),
        ];

        $zone = new Zone('example.com', $records, $soa);
        $this->assertInstanceOf(Zone::class, $zone);

        $contents = File::export($zone);

        $this->assertStringContainsString('a.a.a.a', $contents);
        $this->assertStringContainsString('b:b::b:b:b', $contents);
    }

    public function testCanImportZoneWithTemplateRecords(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
www 600 IN AAAA b:b::b:b:b
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);

        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertCount(1, $zone->records);
        $this->assertSame('www.example.com', $zone->records[0]->name);
        $this->assertSame('b:b::b:b:b', $zone->records[0]->rdata);
    }

    public function testImportExportRoundTripForCaa(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN CAA 0 issue "letsencrypt.org"
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_CAA);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('0 issue "letsencrypt.org"', $record->rdata);

        $exported = File::export($zone, includeComments: false);
        $roundTrip = File::import($exported);
        $roundTripCaa = $this->findRecord($roundTrip->records, Record::TYPE_CAA);

        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $roundTripCaa);
        $this->assertSame($record->rdata, $roundTripCaa->rdata);
    }

    public function testImportCaaMissingQuotedValueFails(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/CAA value must be quoted/');

        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN CAA 0 issue letsencrypt.org
ZONE,
            self::DEFAULT_SOA,
        );

        File::import($contents);
    }

    public function testImportPtrWithReverseOrigin(): void
    {
        $contents = <<<'ZONE'
$ORIGIN 2.0.192.in-addr.arpa.
@ 3600 IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
1 3600 IN PTR host.example.com.
ZONE;

        $zone = File::import($contents);
        $ptr = $this->findRecord($zone->records, Record::TYPE_PTR);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $ptr);
        $this->assertSame('1.2.0.192.in-addr.arpa', $ptr->name);
    }

    public function testImportFailsWithDuplicateSoa(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Multiple SOA records found');

        $contents = <<<'ZONE'
$ORIGIN example.com.
@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
@ IN SOA ns2.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
ZONE;

        File::import($contents);
    }

    public function testImportRejectsTtlWithSuffix(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage("Invalid record type '1H' (line 3).");

        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
www 1h IN A 192.0.2.10
ZONE,
            self::DEFAULT_SOA,
        );

        File::import($contents);
    }

    public function testImportSupportsAlternativeClasses(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
www CS A 192.0.2.10
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_A);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame(Record::CLASS_CS, $record->class);
    }

    public function testImportTxtWithEmbeddedQuoteAndBackslash(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN TXT "a \"quote\" and a \\ backslash"
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('a "quote" and a \\ backslash', $record->rdata);
    }

    public function testImportTxtThreeDigitEscapeConsumesOnlyThreeDigits(): void
    {
        $contents = \sprintf(
            <<<'ZONE'
$ORIGIN example.com.
%s
@ 3600 IN TXT "foo\0100bar"
ZONE,
            self::DEFAULT_SOA,
        );

        $zone = File::import($contents);
        $record = $this->findRecord($zone->records, Record::TYPE_TXT);
        $this->assertInstanceOf(\Utopia\DNS\Message\Record::class, $record);
        $this->assertSame('foo' . \chr(10) . '0bar', $record->rdata);
    }

    public function testImportFailsWhenSoaHasTooFewFields(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM');

        File::import('@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600', 'example.com');
    }

    public function testImportFailsWithoutSoa(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('No SOA record found in zone file');

        File::import("www IN A 192.168.1.10\n", 'example.com');
    }

    public function testImportFailsWhenOwnerOmittedWithoutContext(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Owner omitted but no previous owner available');

        File::import(<<<ZONE
\$ORIGIN example.com.
    IN A 127.0.0.1
@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800
ZONE);
    }

    /**
     * @param list<Record> $records
     */
    private function findRecord(array $records, int $type): ?Record
    {
        foreach ($records as $record) {
            if ($record->type === $type) {
                return $record;
            }
        }

        return null;
    }
}
