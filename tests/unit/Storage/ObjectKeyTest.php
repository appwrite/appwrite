<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Appwrite\Extend\Exception;
use Appwrite\Storage\ObjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectKeyTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string, string}>
     */
    public static function validKeys(): \Iterator
    {
        yield 'bucket root' => ['logo.png', '', 'logo.png'];
        yield 'one folder' => ['photos/pink.png', 'photos/', 'pink.png'];
        yield 'nested folders' => ['photos/2026/pink.png', 'photos/2026/', 'pink.png'];
        yield 'name without extension' => ['reports/q3', 'reports/', 'q3'];
        yield 'name with spaces' => ['my report.pdf', '', 'my report.pdf'];
        yield 'dots inside a name' => ['archive.tar.gz', '', 'archive.tar.gz'];
        yield 'dot leading a name' => ['.env', '', '.env'];
    }

    #[DataProvider('validKeys')]
    public function testParseSplitsFolderFromName(string $key, string $folder, string $name): void
    {
        $object = ObjectKey::parse($key);

        $this->assertSame($folder, $object['folder']);
        $this->assertSame($name, $object['name']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function invalidKeys(): \Iterator
    {
        yield 'empty' => [''];
        yield 'folder marker' => ['photos/'];
        yield 'trailing slash after nesting' => ['photos/2026/'];
        yield 'leading slash' => ['/logo.png'];
        yield 'empty segment' => ['photos//pink.png'];
        yield 'current directory segment' => ['photos/./pink.png'];
        yield 'parent directory segment' => ['photos/../pink.png'];
        yield 'control character' => ["photos/\x00/pink.png"];
    }

    #[DataProvider('invalidKeys')]
    public function testParseRejectsKeysThatDoNotAddressAFile(string $key): void
    {
        $this->expectException(Exception::class);

        ObjectKey::parse($key);
    }

    public function testParseNormalizesFolderWithATrailingSlash(): void
    {
        // The folder is stored with a trailing slash, so a parsed key can be
        // matched against the stored value without further massaging.
        $this->assertSame('photos/2026/', ObjectKey::parse('photos/2026/pink.png')['folder']);
        $this->assertSame('', ObjectKey::parse('pink.png')['folder']);
    }
}
