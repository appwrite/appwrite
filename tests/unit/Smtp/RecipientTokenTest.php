<?php

declare(strict_types=1);

namespace Tests\Unit\Smtp;

use Appwrite\Smtp\RecipientToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecipientTokenTest extends TestCase
{
    public function test_round_trip_and_expiry(): void
    {
        $tokens = new RecipientToken('secret', 60);
        $token = $tokens->issue(['recipient' => 'support@example.com'], 100);

        $claims = $tokens->verify($token, 120);
        $this->assertSame('support@example.com', $claims['recipient']);
        $this->assertSame(160, $claims['exp']);

        $this->expectException(InvalidArgumentException::class);
        $tokens->verify($token, 161);
    }

    public function test_rejects_tampering(): void
    {
        $tokens = new RecipientToken('secret', 60);

        $this->expectException(InvalidArgumentException::class);
        $tokens->verify($tokens->issue(['recipient' => 'support@example.com']).'changed');
    }

    public function test_rejects_empty_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecipientToken('');
    }

    public function test_rejects_malformed_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RecipientToken('secret'))->verify('not-a-token');
    }
}
