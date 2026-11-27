<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Lease;

use Appwrite\Distribution\Lease\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    private const string SECRET = 'lease-secret';

    private const string LEASE = '0123456789abcdef0123456789abcdef0123';

    private const string RUNNER = 'runner1';

    private const string UUID = '0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d9e0f';

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function sequences(): \Iterator
    {
        yield 'sql digits' => ['42'];
        yield 'mongodb uuid7' => [self::UUID];
    }

    #[DataProvider('sequences')]
    public function testRoundTrip(string $projectInternalId): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, $projectInternalId, self::SECRET);

        $verified = Token::verify($token, self::SECRET);

        $this->assertInstanceOf(Token::class, $verified);
        $this->assertSame(self::LEASE, $verified->leaseId);
        $this->assertSame(self::RUNNER, $verified->runnerId);
        $this->assertSame($projectInternalId, $verified->projectInternalId);
    }

    public function testIssueCarriesAFreshNonce(): void
    {
        $this->assertNotSame(
            Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET),
            Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET),
        );
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function malformedSequences(): \Iterator
    {
        yield 'empty' => [''];
        yield 'negative' => ['-42'];
        yield 'dash out of place' => ['0192f0c57-a1b-7c3d-8e4f-5a6b7c8d9e0f'];
        yield 'non-hex character' => ['0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d9e0g'];
        yield 'length 35' => ['0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d9e0'];
        yield 'length 37' => ['0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d9e0f0'];
        yield 'extra dash' => ['0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d-e0f'];
    }

    #[DataProvider('malformedSequences')]
    public function testVerifyRejectsAMalformedProjectSequence(string $projectInternalId): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, $projectInternalId, self::SECRET);

        $this->assertNull(Token::verify($token, self::SECRET));
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function malformedIds(): \Iterator
    {
        yield 'lease id of 35 characters' => [\substr(self::LEASE, 0, 35), self::RUNNER];
        yield 'lease id of 37 characters' => [self::LEASE . 'a', self::RUNNER];
        yield 'lease id not hex' => [\str_repeat('z', 36), self::RUNNER];
        yield 'runner id with a leading hyphen' => [self::LEASE, '-runner'];
        yield 'runner id over 36 characters' => [self::LEASE, \str_repeat('r', 37)];
        yield 'empty runner id' => [self::LEASE, ''];
    }

    #[DataProvider('malformedIds')]
    public function testVerifyRejectsAMalformedId(string $leaseId, string $runnerId): void
    {
        $token = Token::issue($leaseId, $runnerId, '42', self::SECRET);

        $this->assertNull(Token::verify($token, self::SECRET));
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function parts(): \Iterator
    {
        yield 'lease id' => [0];
        yield 'runner id' => [1];
        yield 'project internal id' => [2];
    }

    #[DataProvider('parts')]
    public function testVerifyRejectsADotInAPart(int $part): void
    {
        $values = [self::LEASE, self::RUNNER, '42'];
        $values[$part] = \substr($values[$part], 0, 1) . '.' . \substr($values[$part], 1);

        $this->assertNull(Token::verify(Token::issue(...[...$values, self::SECRET]), self::SECRET));
    }

    public function testVerifyRejectsATamperedMac(): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET);
        $tampered = \substr($token, 0, -1) . (\str_ends_with($token, '0') ? '1' : '0');

        $this->assertNull(Token::verify($tampered, self::SECRET));
    }

    public function testVerifyRejectsAnotherProjectUnderTheSameMac(): void
    {
        $parts = \explode('.', Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET));
        $parts[2] = '43';

        $this->assertNull(Token::verify(\implode('.', $parts), self::SECRET));
    }

    public function testVerifyRejectsAMalformedNonce(): void
    {
        $parts = \explode('.', Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET));
        $parts[3] = \substr($parts[3], 0, 63);

        $this->assertNull(Token::verify(\implode('.', $parts), self::SECRET));
    }

    public function testVerifyRejectsTheWrongSecret(): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET);

        $this->assertNull(Token::verify($token, 'another-secret'));
    }

    public function testVerifyRejectsTheWrongNumberOfParts(): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET);

        $this->assertNull(Token::verify(\substr($token, \strpos($token, '.') + 1), self::SECRET));
        $this->assertNull(Token::verify($token . '.00', self::SECRET));
        $this->assertNull(Token::verify('', self::SECRET));
    }

    public function testVerifyFailsClosedWithAnEmptySecret(): void
    {
        $token = Token::issue(self::LEASE, self::RUNNER, '42', self::SECRET);

        $this->assertNull(Token::verify($token, ''));
    }

    public function testIssueRefusesAnEmptySecret(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Token::KEY_REQUIRED);

        Token::issue(self::LEASE, self::RUNNER, '42', '');
    }
}
