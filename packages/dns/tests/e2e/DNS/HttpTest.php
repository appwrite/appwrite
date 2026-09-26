<?php

declare(strict_types=1);

namespace Tests\E2E\Utopia\DNS;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

final class HttpTest extends TestCase
{
    public const string ENDPOINT = 'http://127.0.0.1:5301';

    public function testPostQuery(): void
    {
        $query = Message::query(new Question('dev.appwrite.io', Record::TYPE_A));

        $response = $this->request('POST', self::ENDPOINT, $query->encode());
        $message = Message::decode($response);

        $this->assertCount(1, $message->answers);
        $this->assertSame('dev.appwrite.io', $message->answers[0]->name);
        $this->assertSame(Record::TYPE_A, $message->answers[0]->type);
        $this->assertSame('180.12.3.24', $message->answers[0]->rdata);
    }

    public function testGetQuery(): void
    {
        $query = Message::query(new Question('dev.appwrite.io', Record::TYPE_TXT));
        $encoded = rtrim(strtr(base64_encode($query->encode()), '+/', '-_'), '=');

        $response = $this->request('GET', self::ENDPOINT . '?dns=' . $encoded);
        $message = Message::decode($response);

        $this->assertCount(1, $message->answers);
        $this->assertSame(Record::TYPE_TXT, $message->answers[0]->type);
        $this->assertSame('awesome-secret-key', $message->answers[0]->rdata);
    }

    public function testLargeResponseIsNotTruncated(): void
    {
        $query = Message::query(new Question('large.localhost', Record::TYPE_TXT));

        $response = $this->request('POST', self::ENDPOINT, $query->encode());
        $message = Message::decode($response);

        $this->assertFalse($message->header->truncated);
        $this->assertCount(8, $message->answers);
    }

    public function testInvalidRequests(): void
    {
        $this->assertNull($this->tryRequest('GET', self::ENDPOINT . '?dns=!!!'));
        $this->assertNull($this->tryRequest('GET', self::ENDPOINT));
        $this->assertNull($this->tryRequest('POST', self::ENDPOINT, 'raw', 'text/plain'));
        $this->assertNull($this->tryRequest('DELETE', self::ENDPOINT));
    }

    protected function request(string $method, string $url, string $body = '', string $contentType = 'application/dns-message'): string
    {
        $response = $this->tryRequest($method, $url, $body, $contentType);
        $this->assertNotNull($response, \sprintf('%s %s failed', $method, $url));

        return $response;
    }

    protected function tryRequest(string $method, string $url, string $body = '', string $contentType = 'application/dns-message'): ?string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $body === '' ? [] : ['Content-Type: ' . $contentType],
            'content' => $body,
            'ignore_errors' => false,
            'timeout' => 5,
        ]]);

        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }
}
