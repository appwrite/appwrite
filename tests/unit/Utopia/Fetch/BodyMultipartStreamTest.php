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
     * Golden fixture: the exact bytes open-runtimes/executor emits for a streamed execution
     * (x-executor-response-format 0.12.0). Pins the cross-repo wire contract, so a change on
     * either side that breaks the other fails here.
     */
    public function testRealExecutorEnvelopeIsParsedInOrder(): void
    {
        $wire = "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"statusCode\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "3\r\n200\r\n0\r\n\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"headers\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "1d\r\n{\"content-type\":\"text\\/html\"}\r\n0\r\n\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"body\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "7\r\n<html>\n\r\n"
            . "8\r\n</html>\n\r\n"
            . "0\r\n\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"logs\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "0\r\n\r\n"
            . "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"duration\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "6\r\n0.4213\r\n0\r\n\r\n"
            . '--BOUNDARY--';

        $completed = [];
        $parts = [];
        $stream = new BodyMultipartStream('BOUNDARY', function (string $name, string $data, bool $isLast) use (&$parts, &$completed): void {
            $parts[$name] = ($parts[$name] ?? '') . $data;
            if ($isLast) {
                $completed[] = $name;
            }
        });

        foreach (\str_split($wire, 3) as $run) {
            $stream->feed($run);
        }

        $this->assertTrue($stream->isComplete());
        $this->assertSame(['statusCode', 'headers', 'body', 'logs', 'duration'], $completed);
        $this->assertSame('200', $parts['statusCode']);
        $this->assertSame(['content-type' => 'text/html'], \json_decode($parts['headers'], true));
        $this->assertSame("<html>\n</html>\n", $parts['body']);
        $this->assertSame('', $parts['logs']);
        $this->assertSame('0.4213', $parts['duration']);
    }

    public function testStatusAndHeadersCompleteBeforeBodyBegins(): void
    {
        // The proxy relies on this: it cannot set a status code or headers once content is out.
        $order = [];
        $stream = new BodyMultipartStream('X', function (string $name, string $data, bool $isLast) use (&$order): void {
            if ($isLast || $data !== '') {
                $order[] = $name . ($isLast ? ':end' : '');
            }
        });

        $stream->feed($this->envelope([
            'statusCode' => '200',
            'headers' => '{}',
            'body' => ['a', 'b'],
        ]));

        $this->assertSame(
            ['statusCode', 'statusCode:end', 'headers', 'headers:end', 'body', 'body', 'body:end'],
            $order
        );
    }

    public function testDataAfterTheClosingDelimiterIsIgnored(): void
    {
        $calls = 0;
        $stream = new BodyMultipartStream('X', function () use (&$calls): void {
            $calls++;
        });

        $stream->feed($this->envelope(['body' => 'done']));
        $this->assertTrue($stream->isComplete());

        $before = $calls;
        $stream->feed('trailing garbage that should never be parsed');

        $this->assertSame($before, $calls);
        $this->assertTrue($stream->isComplete());
    }

    public function testMalformedChunkSizeIsRejected(): void
    {
        $stream = new BodyMultipartStream('X', function (): void {
        });

        $this->expectException(Exception::class);

        $stream->feed(
            "--X\r\n"
            . "Content-Disposition: form-data; name=\"body\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "zz\r\nhello\r\n"
        );
    }

    public function testPartWithoutANameIsRejected(): void
    {
        $stream = new BodyMultipartStream('X', function (): void {
        });

        $this->expectException(Exception::class);

        $stream->feed(
            "--X\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "1\r\na\r\n0\r\n\r\n"
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
