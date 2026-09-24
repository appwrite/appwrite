<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Issuers\Symmetric;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Symmetric\Jwt;
use Utopia\Auth\Verifiers\Symmetric;

final class JwtTest extends TestCase
{
    /** @param string|list<string> $audience */
    #[TestWith(['preview'])]
    #[TestWith([['preview', 'other']])]
    public function testRoundTripPreservesCustomClaimsAndProtectsRegisteredClaims(string|array $audience): void
    {
        $secret = Jwt::generateSecret();
        $issuer = new Jwt($secret, 'https://example.com');
        $before = time();
        $token = $issuer->issue($audience, 600, [
            'purpose' => 'state',
            'iss' => 'https://wrong.example.com',
            'aud' => 'wrong',
            'iat' => 0,
            'exp' => 0,
        ]);
        $after = time();

        $claims = (new Symmetric($secret, issuer: 'https://example.com', audience: 'preview', type: 'JWT'))
            ->verify($token);

        $this->assertSame('https://example.com', $claims['iss']);
        $this->assertSame($audience, $claims['aud']);
        $this->assertSame('state', $claims['purpose']);
        $this->assertIsInt($claims['iat']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertSame($claims['iat'] + 600, $claims['exp']);
    }

    /** @param string|array<mixed> $audience */
    #[TestWith([''])]
    #[TestWith([[]])]
    #[TestWith([['']])]
    #[TestWith([['preview', '']])]
    #[TestWith([['recipient' => 'preview']])]
    #[TestWith([[1 => 'preview']])]
    #[TestWith([['preview', 123]])]
    #[TestWith([[null]])]
    #[TestWith([[false]])]
    #[TestWith([[['preview']]])]
    public function testRejectsInvalidAudience(string|array $audience): void
    {
        $issuer = new Jwt(Jwt::generateSecret(), 'https://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $issuer->issue($audience, 600);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function testRejectsNonPositiveDuration(int $duration): void
    {
        $issuer = new Jwt(Jwt::generateSecret(), 'https://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $issuer->issue('preview', $duration);
    }
}
