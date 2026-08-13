<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Exception;
use Utopia\SMTP\Mime\Header;

final class HeaderTest extends TestCase
{
    /**
     * Unfolding is defined as deleting the line ending and keeping the space
     * after it. Whatever folding does, this has to put it back exactly.
     */
    private function unfold(string $line): string
    {
        return str_replace("\r\n ", ' ', rtrim($line, "\r\n"));
    }

    /**
     * @return list<int>
     */
    private function lengths(string $line): array
    {
        return array_map(strlen(...), explode("\r\n", rtrim($line, "\r\n")));
    }

    public function testShortHeaderIsLeftAlone(): void
    {
        $this->assertSame("Subject: Hello\r\n", Header::line('Subject', 'Hello'));
    }

    public function testLongHeaderIsFolded(): void
    {
        $value = trim(str_repeat('a very long subject ', 12));
        $line = Header::line('Subject', $value);

        $this->assertGreaterThan(1, \count($this->lengths($line)));

        foreach ($this->lengths($line) as $length) {
            $this->assertLessThanOrEqual(78, $length);
        }
    }

    public function testFoldingIsReversible(): void
    {
        $value = trim(str_repeat('a very long subject ', 12));

        $this->assertSame("Subject: {$value}", $this->unfold(Header::line('Subject', $value)));
    }

    public function testAnAddressListFoldsWithinTheLimit(): void
    {
        $addresses = [];

        for ($index = 0; $index < 30; ++$index) {
            $addresses[] = "\"Recipient Number {$index}\" <recipient{$index}@example.test>";
        }

        $value = implode(', ', $addresses);
        $line = Header::line('To', $value);

        foreach ($this->lengths($line) as $length) {
            $this->assertLessThanOrEqual(78, $length);
        }

        $this->assertSame("To: {$value}", $this->unfold($line));
    }

    public function testNonAsciiBecomesEncodedWords(): void
    {
        $line = Header::line('Subject', 'Hellö');

        $this->assertSame("Subject: =?UTF-8?B?SGVsbMO2?=\r\n", $line);
    }

    public function testNoEncodedWordExceedsItsLimit(): void
    {
        $line = Header::line('Subject', str_repeat('héllo ', 20));

        foreach (explode(' ', $this->unfold($line)) as $word) {
            if (str_starts_with($word, '=?')) {
                // RFC 2047 section 2, delimiters included.
                $this->assertLessThanOrEqual(75, \strlen($word));
            }
        }
    }

    public function testEveryEncodedWordDecodesOnItsOwn(): void
    {
        $subject = str_repeat('héllo wörld ', 12);
        $decoded = '';

        foreach (explode(' ', $this->unfold(Header::line('Subject', $subject))) as $word) {
            if (preg_match('/^=\?UTF-8\?B\?(.*)\?=$/', $word, $matches) === 1) {
                $part = base64_decode($matches[1], true);

                // A word split through the middle of a character would not
                // survive this on its own. The /u check needs no extension.
                $this->assertIsString($part);
                $this->assertSame(1, preg_match('//u', $part), 'word is not valid UTF-8 by itself');

                $decoded .= $part;
            }
        }

        $this->assertSame($subject, $decoded);
    }

    public function testRefusesAValueItCannotBreak(): void
    {
        $this->expectException(Exception::class);

        Header::line('X-Token', str_repeat('a', 999));
    }

    public function testPhraseQuotesAsciiAndEncodesTheRest(): void
    {
        $this->assertSame('"Jane Doe"', Header::phrase('Jane Doe'));
        $this->assertSame('=?UTF-8?B?SsOkbmU=?=', Header::phrase('Jäne'));
    }
}
