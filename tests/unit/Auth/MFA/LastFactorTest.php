<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\MFA;

use Appwrite\Auth\MFA\Type\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class LastFactorTest extends TestCase
{
    /**
     * @return iterable<string, array{Document, Document, bool}>
     */
    public static function users(): iterable
    {
        $verified = new Document(['verified' => true]);

        yield 'MFA on, the authenticator is the only verified factor' => [new Document(['mfa' => true]), $verified, true];
        yield 'MFA off' => [new Document(['mfa' => false]), $verified, false];
        yield 'MFA on with a verified email' => [new Document(['mfa' => true, 'emailVerification' => true]), $verified, false];
        yield 'MFA on with a verified phone' => [new Document(['mfa' => true, 'phoneVerification' => true]), $verified, false];
        yield 'MFA on, the authenticator is not verified' => [new Document(['mfa' => true]), new Document(['verified' => false]), false];
    }

    #[DataProvider('users')]
    public function testIsLastFactor(Document $user, Document $authenticator, bool $last): void
    {
        $this->assertSame($last, TOTP::isLastFactor($user, $authenticator));
    }
}
