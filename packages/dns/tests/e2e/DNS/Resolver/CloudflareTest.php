<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Resolver;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Protocol;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver\Cloudflare;

final class CloudflareTest extends TestCase
{
    public function testResolveGoogleA(): void
    {
        $resolver = new Cloudflare();

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'google.com',
                type: Record::TYPE_A,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertNotEmpty($response->answers);
        $this->assertInstanceOf(Record::class, $response->answers[0] ?? null);

        $record = $response->answers[0];
        $this->assertSame(Record::TYPE_A, $record->type);
        $this->assertSame('google.com', $record->name);
        $this->assertNotFalse(filter_var($record->rdata, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
    }

    public function testResolveGoogleAAAA(): void
    {
        $resolver = new Cloudflare();

        $response = $resolver->resolve(new Query(Message::query(
            new Question(
                name: 'google.com',
                type: Record::TYPE_AAAA,
            ),
        ), '127.0.0.1', 53, Protocol::Udp));

        $this->assertNotEmpty($response->answers);
        $this->assertInstanceOf(Record::class, $response->answers[0] ?? null);

        $record = $response->answers[0];
        $this->assertSame(Record::TYPE_AAAA, $record->type);
        $this->assertSame('google.com', $record->name);
        $this->assertNotFalse(filter_var($record->rdata, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
    }
}
