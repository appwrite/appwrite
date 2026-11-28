<?php

// The inputs of the compression corpus (../../compression.php), shared with the generator of what
// PECL zstd, lz4 and snappy wrote for them (pecl.php).

/** `$size` bytes of text: words of a fixed vocabulary in a seeded random order. */
function text(int $size, int $seed = 42): string
{
    $randomizer = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed));
    $words = [];
    for ($i = 0; $i < 2000; $i++) {
        $words[] = bin2hex($randomizer->getBytes(1 + $i % 5));
    }
    $parts = [];
    $length = 0;
    while ($length < $size) {
        $word = $words[$randomizer->getInt(0, 1999)] . ($randomizer->getInt(0, 9) === 0 ? ",\n" : ' ');
        $parts[] = $word;
        $length += strlen($word);
    }

    return substr(implode('', $parts), 0, $size);
}

function random(int $size, int $seed = 7): string
{
    return $size === 0 ? '' : (new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed)))->getBytes($size);
}

$resources = __DIR__ . '/../../../../../packages/compression/tests/resources';
$inputs = [
    'empty' => '',
    'one byte' => 'a',
    'nul' => "\0",
    'short' => 'This is a demo string',
    'all bytes' => implode('', array_map(chr(...), range(0, 255))),
    'utf-8' => "日本語 é",
    'text 4 KB' => text(4096),
    'text 64 KB + 1' => text(65537),
    'random 4 KB' => random(4096),
    'zeros 256 KB' => str_repeat("\0", 256 << 10),
    'text 1 MB' => text(1 << 20),
    'random 1 MB' => random(1 << 20),
    'lorem' => file_get_contents($resources . '/disk-a/lorem.txt'),
    'jpg' => file_get_contents($resources . '/disk-a/kitten-1.jpg'),
    'png' => file_get_contents($resources . '/disk-b/kitten-1.png'),
];
/** The inputs small enough to run every level on. */
$small = array_filter($inputs, fn (string $input): bool => strlen($input) <= 65537);
/** What the 65 MB cases compress. */
function large(): string
{
    return text(64 << 20, 11) . random(1 << 20, 12);
}
