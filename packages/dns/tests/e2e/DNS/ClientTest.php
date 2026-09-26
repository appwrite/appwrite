<?php

declare(strict_types=1);

namespace Tests\E2E\Utopia\DNS;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

final class ClientTest extends TestCase
{
    public const int PORT = 5300;

    public function testTcpQueries(): void
    {
        $client = new Client('127.0.0.1', self::PORT, 5, true);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_A),
        ));

        $records = $response->answers;

        $this->assertCount(2, $records);
        $this->assertSame('dev2.appwrite.io', $records[0]->name);
        $this->assertSame(Record::TYPE_A, $records[0]->type);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(1800, $records[0]->ttl);
        // RRSet order is randomized for load balancing per RFC 2181
        $rdataValues = array_map(fn(\Utopia\DNS\Message\Record $r): string => $r->rdata, $records);
        $this->assertEqualsCanonicalizing(['142.6.0.1', '142.6.0.2'], $rdataValues);
    }

    public function testARecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('dev.appwrite.io', Record::TYPE_A),
        ));
        $records = $response->answers;

        $this->assertCount(1, $records);
        $this->assertInstanceOf(Record::class, $records[0]);
        $this->assertSame('dev.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(10, $records[0]->ttl);
        $this->assertSame(Record::TYPE_A, $records[0]->type);
        $this->assertSame('180.12.3.24', $records[0]->rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_A),
        ));
        $records = $response->answers;

        $this->assertCount(2, $records);
        $this->assertSame('dev2.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(1800, $records[0]->ttl);
        $this->assertSame(Record::TYPE_A, $records[0]->type);
        // RRSet order is randomized for load balancing per RFC 2181
        $rdataValues = array_map(fn(\Utopia\DNS\Message\Record $r): string => $r->rdata, $records);
        $this->assertEqualsCanonicalizing(['142.6.0.1', '142.6.0.2'], $rdataValues);

        $response = $client->query(Message::query(
            new Question('dev3.appwrite.io', Record::TYPE_A),
        ));
        $this->assertCount(0, $response->answers);
    }

    public function testAAAARecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('dev.appwrite.io', Record::TYPE_AAAA),
        ));
        $records = $response->answers;

        $this->assertCount(1, $records);
        $this->assertSame('dev.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(20, $records[0]->ttl);
        $this->assertSame(Record::TYPE_AAAA, $records[0]->type);
        $this->assertSame('2001:db8::ff00:42:8329', $records[0]->rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_AAAA),
        ));
        $records = $response->answers;

        $this->assertCount(2, $records);
        // RRSet order is randomized for load balancing per RFC 2181
        $rdataValues = array_map(fn(\Utopia\DNS\Message\Record $r): string => $r->rdata, $records);
        $this->assertEqualsCanonicalizing(['2001:db8::ff00:0:1', '2001:db8::ff00:0:2'], $rdataValues);

        $response = $client->query(Message::query(
            new Question('dev3.appwrite.io', Record::TYPE_AAAA),
        ));
        $this->assertCount(0, $response->answers);
    }

    public function testCnameRecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('alias.appwrite.io', Record::TYPE_CNAME),
        ));
        $records = $response->answers;

        $this->assertCount(1, $records);
        $this->assertSame('alias.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(30, $records[0]->ttl);
        $this->assertSame(Record::TYPE_CNAME, $records[0]->type);
        $this->assertSame('cloud.appwrite.io', $records[0]->rdata);

        $response = $client->query(Message::query(
            new Question('alias-missing.appwrite.io', Record::TYPE_CNAME),
        ));
        $records = $response->answers;

        $this->assertCount(0, $records);
    }

    public function testTxtRecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('dev.appwrite.io', Record::TYPE_TXT),
        ));
        $records = $response->answers;

        $this->assertCount(1, $records);
        $this->assertSame('dev.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(30, $records[0]->ttl);
        $this->assertSame(Record::TYPE_TXT, $records[0]->type);
        $this->assertSame('awesome-secret-key', $records[0]->rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_TXT),
        ));
        $this->assertCount(0, $response->answers);

        $response = $client->query(Message::query(
            new Question('dev3.appwrite.io', Record::TYPE_TXT),
        ));
        $this->assertCount(0, $response->answers);
    }

    public function testNsRecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('delegated.appwrite.io', Record::TYPE_NS),
        ));
        $this->assertCount(0, $response->answers);

        $authority = $response->authority;
        $this->assertCount(2, $authority);
        $this->assertSame('delegated.appwrite.io', $authority[0]->name);
        $this->assertSame(Record::CLASS_IN, $authority[0]->class);
        $this->assertSame(30, $authority[0]->ttl);
        $this->assertSame(Record::TYPE_NS, $authority[0]->type);
        $this->assertSame(Record::TYPE_NS, $authority[1]->type);
        $this->assertSame('ns1.test.io', $authority[0]->rdata);
        $this->assertSame('ns2.test.io', $authority[1]->rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_NS),
        ));
        $this->assertCount(0, $response->answers);
        $authority = $response->authority;
        $this->assertCount(1, $authority);
        $this->assertSame('appwrite.io', $authority[0]->name);
        $this->assertSame(Record::TYPE_SOA, $authority[0]->type);

        $response = $client->query(Message::query(
            new Question('dev3.appwrite.io', Record::TYPE_NS),
        ));
        $this->assertCount(0, $response->answers);
    }

    public function testCaaRecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('dev.appwrite.io', Record::TYPE_CAA),
        ));
        $records = $response->answers;

        $this->assertCount(1, $records);
        $this->assertSame('dev.appwrite.io', $records[0]->name);
        $this->assertSame(Record::CLASS_IN, $records[0]->class);
        $this->assertSame(Record::TYPE_CAA, $records[0]->type);

        $this->assertSame('0 issue "letsencrypt.org"', $records[0]->rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_CAA),
        ));
        $this->assertCount(0, $response->answers);

        $response = $client->query(Message::query(
            new Question('dev3.appwrite.io', Record::TYPE_CAA),
        ));
        $this->assertCount(0, $response->answers);
    }

    public function testSoaRecords(): void
    {
        $client = new Client('127.0.0.1', self::PORT);
        $response = $client->query(Message::query(
            new Question('appwrite.io', Record::TYPE_SOA),
        ));
        $this->assertCount(0, $response->authority);

        $answers = $response->answers;
        $this->assertCount(1, $answers);
        $this->assertSame('appwrite.io', $answers[0]->name);
        $this->assertSame(Record::CLASS_IN, $answers[0]->class);
        $this->assertSame(30, $answers[0]->ttl);
        $this->assertSame(Record::TYPE_SOA, $answers[0]->type);

        $rdata = $answers[0]->rdata;
        $this->assertStringContainsString('ns1.appwrite.zone', $rdata);
        $this->assertStringContainsString('team.appwrite.io', $rdata);
        $this->assertStringContainsString('1 7200 1800 1209600 3600', $rdata);

        $response = $client->query(Message::query(
            new Question('dev2.appwrite.io', Record::TYPE_SOA),
        ));
        $answers = $response->answers;
        $this->assertCount(0, $answers);

        $authority = $response->authority;
        $this->assertCount(1, $authority);
        $this->assertSame('appwrite.io', $authority[0]->name);

        $rdata = $authority[0]->rdata;
        $this->assertStringContainsString('ns1.appwrite.zone', $rdata);
        $this->assertStringContainsString('team.appwrite.io', $rdata);
        $this->assertStringContainsString('1 7200 1800 1209600 3600', $rdata);
    }

    public function testInvalidServer(): void
    {
        try {
            new Client('not-ip-address', self::PORT);
            $this->fail('Expected invalid IP address exception');
        } catch (\Exception $e) {
            $this->assertSame('Server must be an IP address.', $e->getMessage());
        }

        try {
            new Client('ns1.digitalocean.com', self::PORT);
            $this->fail('Expected invalid IP address exception');
        } catch (\Exception $e) {
            $this->assertSame('Server must be an IP address.', $e->getMessage());
        }

        try {
            $client = new Client('172.64.52.210', self::PORT);
            $this->assertNotEmpty($client);
            $client = new Client('127.0.0.1', self::PORT);
            $this->assertNotEmpty($client);
        } catch (\Exception) {
            $this->fail('IPv4 threw unexpected error');
        }

        try {
            $client = new Client('::1', self::PORT);
            $this->assertNotEmpty($client);
            $client = new Client('2606:4700:52::ac40:34d2', self::PORT);
            $this->assertNotEmpty($client);
        } catch (\Exception) {
            $this->fail('IPv6 threw unexpected error');
        }
    }

    public function testTcpFallbackAfterUdpTruncation(): void
    {
        // Query for large.localhost TXT records - the response is large enough
        // to always trigger truncation over UDP (8 TXT records > 512 bytes)
        $question = new Question('large.localhost', Record::TYPE_TXT);
        $query = Message::query($question);

        // UDP query should be truncated (TC flag set) due to 512-byte limit
        $udpClient = new Client('127.0.0.1', self::PORT);
        $udpResponse = $udpClient->query($query);
        $this->assertTrue($udpResponse->header->truncated, 'UDP response should be truncated for large response');

        // TCP query should return full response without truncation
        $tcpClient = new Client('127.0.0.1', self::PORT, useTcp: true);
        $tcpResponse = $tcpClient->query($query);

        // TCP response should not be truncated
        $this->assertFalse($tcpResponse->header->truncated, 'TCP response should not be truncated');

        // TCP response should have all 8 TXT records from the zone file
        $this->assertCount(8, $tcpResponse->answers, 'TCP should return all 8 TXT records');

        // TCP response should have more answers than truncated UDP
        $this->assertGreaterThan(
            \count($udpResponse->answers),
            \count($tcpResponse->answers),
            'TCP should return more answers than truncated UDP',
        );
    }
}
