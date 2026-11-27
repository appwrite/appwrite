<?php

namespace Appwrite\Distribution;

/**
 * Masking runs on the joined text before it is split, so no secret can straddle a chunk.
 */
final readonly class Logs
{
    public const int CHUNK = 65536;
    public const int LINE = 16384;
    public const int SECRET = 8;
    public const string MASK = '********';
    public const string TRUNCATED = "\n[Build logs reached the size limit. Later output was dropped.]\n";

    /**
     * @var list<string>
     */
    private array $secrets;

    /**
     * @param list<string> $secrets
     */
    public function __construct(array $secrets, private int $limit)
    {
        $secrets = \array_values(\array_unique(\array_filter($secrets, static fn (string $secret): bool => \strlen($secret) >= self::SECRET)));
        \usort($secrets, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $this->secrets = $secrets;
    }

    /**
     * The orchestrator builds an event id as `<jobId>-<UnixNano>`, in emission order.
     */
    public static function sequence(string $eventId): int
    {
        $position = \strrpos($eventId, '-');
        $digits = $position === false ? '' : \substr($eventId, $position + 1);

        return \ctype_digit($digits) ? (int) $digits : 0;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public function chunks(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $text = \implode("\n", $lines) . "\n";
        if ($this->secrets !== []) {
            $text = \str_replace($this->secrets, self::MASK, $text);
        }
        $text = \mb_scrub($text, 'UTF-8');

        $wrapped = [];
        foreach (\explode("\n", $text) as $line) {
            \array_push($wrapped, ...self::split($line, self::LINE));
        }

        return self::split(\implode("\n", $wrapped), self::CHUNK);
    }

    /**
     * @param list<string> $chunks
     * @return list<string>
     */
    public function admit(int $size, array $chunks): array
    {
        $admitted = [];
        if ($size >= $this->limit) {
            return $admitted;
        }

        foreach ($chunks as $chunk) {
            if ($size + \strlen($chunk) > $this->limit) {
                $admitted[] = self::TRUNCATED;
                break;
            }

            $admitted[] = $chunk;
            $size += \strlen($chunk);
        }

        return $admitted;
    }

    /**
     * A stored marker consumes the rest of the budget, so nothing is admitted after it.
     *
     * @return int Bytes to add to a stored size of `$size`, after `$bytes` were stored
     */
    public function increment(int $size, int $bytes, bool $truncated): int
    {
        return $truncated ? \max($bytes, $this->limit - $size) : $bytes;
    }

    /**
     * @return list<string> Pieces of at most `$bytes` bytes, cut on UTF-8 character boundaries
     */
    private static function split(string $text, int $bytes): array
    {
        if (\strlen($text) <= $bytes) {
            return [$text];
        }

        $pieces = [];
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            $piece = \mb_strcut($text, $offset, $bytes, 'UTF-8');
            if ($piece === '') {
                break;
            }

            $pieces[] = $piece;
            $offset += \strlen($piece);
        }

        return $pieces;
    }
}
