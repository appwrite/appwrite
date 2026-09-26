<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Resolver;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Protocol;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Zone;

final class MemoryTest extends TestCase
{
    public function testResolveReturnsExactRecord(): void
    {
        $zone = new Zone(
            name: 'example.com',
            records: [new Record(
                name: 'www.example.com',
                type: Record::TYPE_A,
                rdata: '192.0.2.10',
            )],
            soa: new Record(
                name: 'example.com',
                type: Record::TYPE_SOA,
                rdata: 'ns1.example.com. admin.example.com. 1 3600 600 1209600 300',
            ),
        );

        $resolver = new Memory($zone);

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'www.example.com',
                type: Record::TYPE_A,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertCount(1, $response->answers);
        $this->assertSame('www.example.com', $response->answers[0]->name);
        $this->assertSame(Record::TYPE_A, $response->answers[0]->type);
        $this->assertSame('192.0.2.10', $response->answers[0]->rdata);
        $this->assertEmpty($response->authority);
    }

    public function testResolveNoDataIncludesAuthority(): void
    {
        $zone = new Zone(
            name: 'example.com',
            records: [new Record(
                name: 'www.example.com',
                type: Record::TYPE_A,
                rdata: '192.0.2.10',
            )],
            soa: new Record(
                name: 'example.com',
                type: Record::TYPE_SOA,
                rdata: 'ns1.example.com. admin.example.com. 1 3600 600 1209600 300',
            ),
        );

        $resolver = new Memory($zone);

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'www.example.com',
                type: Record::TYPE_AAAA,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertSame(Message::RCODE_NOERROR, $response->header->responseCode);
        $this->assertEmpty($response->answers);
        $this->assertNotEmpty($response->authority);
        $this->assertSame(Record::TYPE_SOA, $response->authority[0]->type);
    }

    public function testResolveNxDomain(): void
    {
        $zone = new Zone(
            name: 'example.com',
            records: [new Record(
                name: 'www.example.com',
                type: Record::TYPE_A,
                rdata: '192.0.2.10',
            )],
            soa: new Record(
                name: 'example.com',
                type: Record::TYPE_SOA,
                rdata: 'ns1.example.com. admin.example.com. 1 3600 600 1209600 300',
            ),
        );

        $resolver = new Memory($zone);

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'missing.example.com',
                type: Record::TYPE_A,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertSame(Message::RCODE_NXDOMAIN, $response->header->responseCode);
        $this->assertEmpty($response->answers);
        $this->assertNotEmpty($response->authority);
        $this->assertSame(Record::TYPE_SOA, $response->authority[0]->type);
    }

    public function testResolveSoaFallsBackToParentZone(): void
    {
        $zone = new Zone(
            name: 'example.com',
            records: [new Record(
                name: 'www.example.com',
                type: Record::TYPE_A,
                rdata: '192.0.2.10',
            )],
            soa: new Record(
                name: 'example.com',
                type: Record::TYPE_SOA,
                rdata: 'ns1.example.com. admin.example.com. 1 3600 600 1209600 300',
            ),
        );

        $resolver = new Memory($zone);

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'child.www.example.com',
                type: Record::TYPE_SOA,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertSame(Message::RCODE_NXDOMAIN, $response->header->responseCode);
        $this->assertEmpty($response->answers);
        $this->assertNotEmpty($response->authority);
        $this->assertSame(Record::TYPE_SOA, $response->authority[0]->type);
        $this->assertSame('example.com', $response->authority[0]->name);
    }
}
