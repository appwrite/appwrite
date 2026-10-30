<?php

declare(strict_types=1);

namespace Tests\Unit\Alert;

use Appwrite\Alert\Unsubscribe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnsubscribeTest extends TestCase
{
    private const string SECRET = 'unsubscribe-test-secret'; // pragma: allowlist secret

    public function testTokenNamesTheUserAndWhatTheyStop(): void
    {
        $unsubscribe = new Unsubscribe(self::SECRET);

        $this->assertSame(['userId' => 'ana', 'scope' => 'egress'], $unsubscribe->verify($unsubscribe->token('ana', 'egress')));
        $this->assertSame(['userId' => 'ana', 'scope' => Unsubscribe::type('usage')], $unsubscribe->verify($unsubscribe->token('ana', Unsubscribe::type('usage'))));
    }

    public function testTokenExpires(): void
    {
        $unsubscribe = new Unsubscribe(self::SECRET, ttl: 3600);
        $now = 1_800_000_000;
        $token = $unsubscribe->token('ana', 'egress', $now);

        $this->assertSame('ana', $unsubscribe->verify($token, $now + 3599)['userId']);

        $this->expectException(\InvalidArgumentException::class);
        $unsubscribe->verify($token, $now + 3600);
    }

    public static function forged(): \Iterator
    {
        $token = (new Unsubscribe(self::SECRET))->token('ana', 'egress');
        [$header, $payload, $signature] = \explode('.', $token);
        $claims = \json_decode((string) \base64_decode(\strtr($payload, '-_', '+/')), true);
        $encode = fn (array $claims): string => \rtrim(\strtr(\base64_encode((string) \json_encode($claims)), '+/', '-_'), '=');

        yield 'another user' => [$header . '.' . $encode(['userId' => 'ben'] + $claims) . '.' . $signature];
        yield 'every monitor' => [$header . '.' . $encode(['scope' => Unsubscribe::type('usage')] + $claims) . '.' . $signature];
        yield 'another secret' => [(new Unsubscribe('another-secret'))->token('ana', 'egress')];
        yield 'not a token' => ['not-a-token'];
        yield 'empty' => [''];
    }

    #[DataProvider('forged')]
    public function testRefusesAForgedToken(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Unsubscribe(self::SECRET))->verify($token);
    }

    public function testRefusesATokenSignedForSomethingElse(): void
    {
        $other = (new \Ahc\Jwt\JWT(self::SECRET, 'HS256', 3600))->encode(['userId' => 'ana', 'scope' => 'egress', 'purpose' => 'notification_track']);

        $this->expectException(\InvalidArgumentException::class);

        (new Unsubscribe(self::SECRET))->verify($other);
    }
}
