<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\MFA;

use Appwrite\Auth\MFA\Challenge\TOTP;
use Appwrite\Auth\MFA\Type;
use OTPHP\TOTP as TOTPLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class TOTPTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private const int PERIOD = 30;

    /**
     * A moment ten seconds into a time step.
     */
    private const int NOW = 1_800_000_010;

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function steps(): iterable
    {
        yield 'current step' => [0, true];
        yield 'previous step' => [-1, true];
        yield 'next step' => [1, true];
        yield 'two steps back' => [-2, false];
        yield 'two steps ahead' => [2, false];
    }

    #[DataProvider('steps')]
    public function testCodeIsAcceptedOnlyWithinOneStepOfNow(int $offset, bool $accepted): void
    {
        $otp = TOTPLibrary::create(self::SECRET)->at(self::NOW + $offset * self::PERIOD);

        $this->assertSame($accepted, TOTP::verifyAt($this->user(), $otp, self::NOW));
    }

    public function testPreviousStepCodeIsRejectedOnceItsDriftHasPassed(): void
    {
        $lastSecondOfStep = self::NOW + 19;
        $otp = TOTPLibrary::create(self::SECRET)->at(self::NOW - self::PERIOD);

        $this->assertFalse(TOTP::verifyAt($this->user(), $otp, $lastSecondOfStep));
    }

    public function testWrongCodeIsRejected(): void
    {
        $this->assertFalse(TOTP::verifyAt($this->user(), '000000', self::NOW));
    }

    private function user(): Document
    {
        return new Document([
            'authenticators' => [
                new Document([
                    'type' => Type::TOTP,
                    'data' => ['secret' => self::SECRET],
                ]),
            ],
        ]);
    }
}
