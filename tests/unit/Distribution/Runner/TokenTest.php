<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Runner;

use Appwrite\Distribution\Runner\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    private const string SECRET = 'runner-secret';

    private const string RUNNER = '6745a1b2c3d4e5f60718';

    public function testRoundTrip(): void
    {
        $token = Token::issue(self::RUNNER, self::SECRET);

        $verified = Token::verify($token, self::SECRET);

        $this->assertInstanceOf(Token::class, $verified);
        $this->assertSame(self::RUNNER, $verified->runnerId);
    }

    public function testIssueCarriesAFreshNonce(): void
    {
        $this->assertNotSame(Token::issue(self::RUNNER, self::SECRET), Token::issue(self::RUNNER, self::SECRET));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function malformedIds(): \Iterator
    {
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-runner'];
        yield 'leading underscore' => ['_runner'];
        yield 'over 36 characters' => [\str_repeat('r', 37)];
        yield 'dot' => ['run.ner'];
    }

    #[DataProvider('malformedIds')]
    public function testVerifyRejectsAMalformedRunnerId(string $runnerId): void
    {
        $this->assertNull(Token::verify(Token::issue($runnerId, self::SECRET), self::SECRET));
    }

    public function testVerifyRejectsATamperedMac(): void
    {
        $token = Token::issue(self::RUNNER, self::SECRET);
        $tampered = \substr($token, 0, -1) . (\str_ends_with($token, '0') ? '1' : '0');

        $this->assertNull(Token::verify($tampered, self::SECRET));
    }

    public function testVerifyRejectsAnotherRunnerUnderTheSameMac(): void
    {
        $parts = \explode('.', Token::issue(self::RUNNER, self::SECRET));
        $parts[0] = 'runner2';

        $this->assertNull(Token::verify(\implode('.', $parts), self::SECRET));
    }

    public function testVerifyRejectsAMalformedNonce(): void
    {
        $parts = \explode('.', Token::issue(self::RUNNER, self::SECRET));
        $parts[1] = \str_repeat('z', 64);

        $this->assertNull(Token::verify(\implode('.', $parts), self::SECRET));
    }

    public function testVerifyRejectsTheWrongSecret(): void
    {
        $this->assertNull(Token::verify(Token::issue(self::RUNNER, self::SECRET), 'another-secret'));
    }

    public function testVerifyRejectsTheWrongNumberOfParts(): void
    {
        $token = Token::issue(self::RUNNER, self::SECRET);

        $this->assertNull(Token::verify(\substr($token, \strpos($token, '.') + 1), self::SECRET));
        $this->assertNull(Token::verify($token . '.00', self::SECRET));
        $this->assertNull(Token::verify('', self::SECRET));
    }

    public function testVerifyFailsClosedWithAnEmptySecret(): void
    {
        $this->assertNull(Token::verify(Token::issue(self::RUNNER, self::SECRET), ''));
    }

    public function testIssueRefusesAnEmptySecret(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Token::KEY_REQUIRED);

        Token::issue(self::RUNNER, '');
    }
}
