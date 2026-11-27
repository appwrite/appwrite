<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Logs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogsTest extends TestCase
{
    private const int LIMIT = 50_000_000;

    public function testMasksSecretsOfEightOrMoreBytes(): void
    {
        $logs = new Logs(['hunter2-password', 'keystore-pass'], self::LIMIT);

        $chunks = $logs->chunks(['login with hunter2-password', 'signing with keystore-pass and hunter2-password']);

        $this->assertSame(["login with ********\nsigning with ******** and ********\n"], $chunks);
    }

    public function testShortValuesAreNotMasked(): void
    {
        $logs = new Logs(['1234567', 'true', ''], self::LIMIT);

        $this->assertSame(["pin 1234567 is true\n"], $logs->chunks(['pin 1234567 is true']));
    }

    public function testLongerSecretIsMaskedFirst(): void
    {
        $logs = new Logs(['abcdefgh', 'abcdefgh-ijklmnop'], self::LIMIT);

        $this->assertSame(["token ********\n"], $logs->chunks(['token abcdefgh-ijklmnop']));
    }

    public function testSecretAtAChunkBoundaryIsMasked(): void
    {
        $secret = \str_repeat('s', 40);
        $logs = new Logs([$secret], self::LIMIT);
        $line = \str_repeat('a', Logs::CHUNK - 20) . $secret;

        $joined = \implode('', $logs->chunks([$line]));

        $this->assertStringNotContainsString($secret, $joined);
        $this->assertStringNotContainsString(\str_repeat('s', Logs::SECRET), $joined);
        $this->assertStringContainsString(Logs::MASK, $joined);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $logs = new Logs([], self::LIMIT);

        $chunks = $logs->chunks(["broken \xC3\x28 byte", "valid \u{00E9}"]);

        $this->assertCount(1, $chunks);
        $this->assertTrue(\mb_check_encoding($chunks[0], 'UTF-8'));
        $this->assertStringContainsString("valid \u{00E9}", $chunks[0]);
        $this->assertStringNotContainsString("\xC3\x28", $chunks[0]);
    }

    public function testChunksNeverExceedTheLimitOrSplitACharacter(): void
    {
        $logs = new Logs([], self::LIMIT);
        $lines = \array_fill(0, 40, \str_repeat("\u{1F600}", 1000));

        $chunks = $logs->chunks($lines);

        $this->assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(Logs::CHUNK, \strlen($chunk));
            $this->assertTrue(\mb_check_encoding($chunk, 'UTF-8'), 'a chunk ends on a character boundary');
        }
        $this->assertSame(\implode("\n", $lines) . "\n", \implode('', $chunks));
    }

    public function testLongLinesAreWrapped(): void
    {
        $logs = new Logs([], self::LIMIT);
        $line = \str_repeat("\u{00E9}", Logs::LINE);

        $chunks = $logs->chunks([$line]);

        $text = \implode('', $chunks);
        foreach (\explode("\n", $text) as $wrapped) {
            $this->assertLessThanOrEqual(Logs::LINE, \strlen($wrapped));
            $this->assertTrue(\mb_check_encoding($wrapped, 'UTF-8'));
        }
        $this->assertSame($line, \str_replace("\n", '', $text));
        $this->assertSame(\intdiv(\strlen($line), Logs::LINE), \substr_count($text, "\n"));
    }

    #[DataProvider('eventIds')]
    public function testSequenceIsTheNumberAfterTheLastHyphen(string $eventId, int $sequence): void
    {
        $this->assertSame($sequence, Logs::sequence($eventId));
    }

    /**
     * @return \Iterator<string, array{string, int}>
     */
    public static function eventIds(): \Iterator
    {
        yield 'job id with hyphens' => ['dist-0a1b2c-1764324000123456789', 1764324000123456789];
        yield 'plain' => ['job-42', 42];
        yield 'no hyphen' => ['1764324000123456789', 0];
        yield 'trailing hyphen' => ['dist-abc-', 0];
        yield 'not digits' => ['dist-abc-12x', 0];
        yield 'empty' => ['', 0];
    }

    public function testAdmitKeepsChunksUnderTheLimit(): void
    {
        $logs = new Logs([], 100);

        $this->assertSame(['aaaa', 'bbbb'], $logs->admit(92, ['aaaa', 'bbbb']));
    }

    public function testAdmitWritesOneMarkerWhenCrossing(): void
    {
        $logs = new Logs([], 100);

        $this->assertSame(['aaaa', Logs::TRUNCATED], $logs->admit(90, ['aaaa', 'bbbbbbbb', 'cccc']));
    }

    public function testMarkerStopsLaterOutput(): void
    {
        $limit = 1000;
        $size = 100;
        $logs = new Logs([], $limit);

        $admitted = $logs->admit($size, [\str_repeat('a', 950)]);
        $this->assertSame([Logs::TRUNCATED], $admitted);
        $this->assertGreaterThan(\strlen(Logs::TRUNCATED), $limit - $size);

        $size += $logs->increment($size, \strlen(Logs::TRUNCATED), true);

        $this->assertSame($limit, $size);
        $this->assertSame([], $logs->admit($size, ['b']));
    }

    public function testMarkerPastAFullBudgetAddsItsBytes(): void
    {
        $logs = new Logs([], 100);

        $this->assertSame(\strlen(Logs::TRUNCATED) + 4, $logs->increment(90, \strlen(Logs::TRUNCATED) + 4, true));
    }

    public function testIncrementWithoutMarkerIsTheStoredBytes(): void
    {
        $logs = new Logs([], 100);

        $this->assertSame(8, $logs->increment(10, 8, false));
    }

    #[DataProvider('pastTheLimit')]
    public function testAdmitDropsEverythingPastTheLimit(int $size): void
    {
        $logs = new Logs([], 100);

        $this->assertSame([], $logs->admit($size, ['aaaa']));
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function pastTheLimit(): \Iterator
    {
        yield 'at the limit' => [100];
        yield 'past the limit after the marker' => [100 + \strlen(Logs::TRUNCATED)];
    }
}
