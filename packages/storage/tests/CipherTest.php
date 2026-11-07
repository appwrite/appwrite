<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Storage\Cipher;
use Utopia\Storage\Exception\StorageException;

final class CipherTest extends TestCase
{
    private Cipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new Cipher(\str_repeat('k', 32));
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function sizes(): \Iterator
    {
        yield 'empty' => [0];
        yield 'one byte' => [1];
        yield 'one segment' => [Cipher::SEGMENT];
        yield 'segment and a byte' => [Cipher::SEGMENT + 1];
        yield 'many segments' => [Cipher::SEGMENT * 3 + 100];
    }

    #[DataProvider('sizes')]
    public function testRoundTrip(int $size): void
    {
        $data = \random_bytes(\max(1, $size));
        $data = \substr($data, 0, $size);

        $encrypted = $this->cipher->encrypt($data, 0, true);

        $this->assertSame(Cipher::size($size), \strlen($encrypted));
        $this->assertSame($data, $this->cipher->decrypt($encrypted, 0, true));
    }

    public function testPartsJoinIntoOneFile(): void
    {
        $first = \random_bytes(Cipher::SEGMENT * 2);
        $second = \random_bytes(100);

        $encrypted = $this->cipher->encrypt($first, 0, false) . $this->cipher->encrypt($second, 2, true);

        $this->assertSame($first . $second, $this->cipher->decrypt($encrypted, 0, true));
    }

    public function testRange(): void
    {
        $data = \random_bytes(Cipher::SEGMENT * 3 + 10);
        $encrypted = $this->cipher->encrypt($data, 0, true);

        $middle = \substr($encrypted, Cipher::offset(1), Cipher::offset(1));
        $this->assertSame(\substr($data, Cipher::SEGMENT, Cipher::SEGMENT), $this->cipher->decrypt($middle, 1, false));

        $tail = \substr($encrypted, Cipher::offset(3));
        $this->assertSame(\substr($data, Cipher::SEGMENT * 3), $this->cipher->decrypt($tail, 3, true));
    }

    public function testTamperedSegmentFails(): void
    {
        $encrypted = $this->cipher->encrypt(\random_bytes(100), 0, true);
        $encrypted[20] = $encrypted[20] === 'a' ? 'b' : 'a';

        $this->expectException(StorageException::class);
        $this->cipher->decrypt($encrypted, 0, true);
    }

    public function testReorderedSegmentsFail(): void
    {
        $encrypted = $this->cipher->encrypt(\random_bytes(Cipher::SEGMENT * 2), 0, true);
        $swapped = \substr($encrypted, Cipher::offset(1)) . \substr($encrypted, 0, Cipher::offset(1));

        $this->expectException(StorageException::class);
        $this->cipher->decrypt($swapped, 0, true);
    }

    public function testCutOffFileFails(): void
    {
        $encrypted = $this->cipher->encrypt(\random_bytes(Cipher::SEGMENT * 2), 0, true);

        $this->expectException(StorageException::class);
        $this->cipher->decrypt(\substr($encrypted, 0, Cipher::offset(1)), 0, true);
    }

    public function testWrongKeyFails(): void
    {
        $encrypted = $this->cipher->encrypt('secret', 0, true);

        $this->expectException(StorageException::class);
        new Cipher(\str_repeat('x', 32))->decrypt($encrypted, 0, true);
    }
}
