<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Adapter\Curl;

use CurlHandle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\DecodedSizeLimit;

final class DecodedSizeLimitTest extends TestCase
{
    /**
     * @return array<string, array{array<int, mixed>, ?int}>
     */
    public static function configurations(): array
    {
        return [
            'no limit' => [[], null],
            'zero means none' => [[\CURLOPT_MAXFILESIZE_LARGE => 0], null],
            'negative means none' => [[\CURLOPT_MAXFILESIZE => -1], null],
            'non-numeric string means none' => [[\CURLOPT_MAXFILESIZE_LARGE => 'unlimited'], null],
            'integer' => [[\CURLOPT_MAXFILESIZE_LARGE => 10_000], 10_000],
            'legacy option' => [[\CURLOPT_MAXFILESIZE => 10_000], 10_000],
            'numeric string, as cURL reads it' => [[\CURLOPT_MAXFILESIZE_LARGE => '10000'], 10_000],
            'both options, the smaller applies' => [[\CURLOPT_MAXFILESIZE_LARGE => 100_000_000, \CURLOPT_MAXFILESIZE => 10_000], 10_000],
        ];
    }

    /**
     * @param array<int, mixed> $options
     */
    #[DataProvider('configurations')]
    public function testItReadsTheCapTheWayCurlDoes(array $options, ?int $bytes): void
    {
        $this->assertSame($bytes, DecodedSizeLimit::fromOptions($options)?->bytes);
    }

    public function testItPassesChunksToTheWriteCallbackUntilTheCap(): void
    {
        $limit = DecodedSizeLimit::fromOptions([\CURLOPT_MAXFILESIZE_LARGE => 10_000]);
        $this->assertInstanceOf(DecodedSizeLimit::class, $limit);

        $chunks = [];
        $exceeded = false;
        $write = $limit->wrap(static function (CurlHandle $handle, string $chunk) use (&$chunks): int {
            $chunks[] = \strlen($chunk);

            return \strlen($chunk);
        }, $exceeded);
        $handle = \curl_init();

        $this->assertSame(6_000, $write($handle, \str_repeat('a', 6_000)));
        $this->assertSame(4_000, $write($handle, \str_repeat('a', 4_000)));
        $this->assertSame([false], [$exceeded]);

        // One byte past the cap: any count other than the chunk's length aborts the transfer
        $this->assertSame(0, $write($handle, 'a'));
        $this->assertSame([true], [$exceeded]);
        $this->assertSame([6_000, 4_000], $chunks);
    }

    public function testItRejectsAWriteCallbackThatIsNotCallable(): void
    {
        $limit = DecodedSizeLimit::fromOptions([\CURLOPT_MAXFILESIZE_LARGE => 10_000]);
        $this->assertInstanceOf(DecodedSizeLimit::class, $limit);

        $exceeded = false;
        $this->expectException(InvalidArgumentException::class);
        $limit->wrap('not a function', $exceeded);
    }
}
