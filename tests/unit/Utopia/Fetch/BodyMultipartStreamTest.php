<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Fetch;

use Appwrite\Utopia\Fetch\BodyMultipartStream;
use Exception;
use PHPUnit\Framework\TestCase;

final class BodyMultipartStreamTest extends TestCase
{
    public function testFeedOneByteAtATimeYieldsWholeParts(): void
    {
        $envelope = $this->envelope([
            'statusCode' => '200',
            'body' => 'hello world',
        ]);

        $parts = [];
        $stream = new BodyMultipartStream('X', function (string $name, string $data) use (&$parts): void {
            $parts[$name] = ($parts[$name] ?? '') . $data;
        });

        foreach (\str_split($envelope) as $byte) {
            $stream->feed($byte);
        }

        $this->assertSame(['statusCode' => '200', 'body' => 'hello world'], $parts);
    }

    public function testBodyCarryingTheBoundaryIsNotSplit(): void
    {
        $poisoned = "before\r\n--X\r\nafter";

        $body = '';
        $stream = new BodyMultipartStream('X', function (string $name, string $data) use (&$body): void {
            $body .= $data;
        });

        $stream->feed($this->envelope(['body' => $poisoned]));

        $this->assertSame($poisoned, $body);
    }

    public function testContentIsEmittedBeforeTheEnvelopeCompletes(): void
    {
        $envelope = $this->envelope(['body' => ['first', 'second']]);

        $body = '';
        $stream = new BodyMultipartStream('X', function (string $name, string $data) use (&$body): void {
            $body .= $data;
        });

        // Everything up to the second run's length prefix.
        $stream->feed(\substr($envelope, 0, \strpos($envelope, 'second') - 3));

        $this->assertSame('first', $body);
    }

    public function testPartCompletionIsEmittedOnce(): void
    {
        $events = [];
        $stream = new BodyMultipartStream('X', function (string $name, string $data, bool $isLast) use (&$events): void {
            $events[] = [$name, $data, $isLast];
        });

        $stream->feed($this->envelope(['body' => ['hello', ' world']]));

        $this->assertSame([
            ['body', 'hello', false],
            ['body', ' world', false],
            ['body', '', true],
        ], $events);
    }

    public function testPartWithoutChunkedEncodingIsRejected(): void
    {
        $stream = new BodyMultipartStream('X', function (): void {
        });

        $this->expectException(Exception::class);

        $stream->feed(
            "--X\r\n"
            . "Content-Disposition: form-data; name=\"body\"\r\n\r\n"
            . "5\r\nhello\r\n0\r\n\r\n"
            . '--X--'
        );
    }

    /**
     * @param array<string, string|array<int, string>> $parts
     */
    private function envelope(array $parts, string $boundary = 'X'): string
    {
        $envelope = '';

        foreach ($parts as $name => $runs) {
            $envelope .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n"
                . "Content-Transfer-Encoding: chunked\r\n\r\n";

            foreach ((array) $runs as $run) {
                $envelope .= \dechex(\strlen($run)) . "\r\n" . $run . "\r\n";
            }

            $envelope .= "0\r\n\r\n";
        }

        return $envelope . '--' . $boundary . '--';
    }
}
