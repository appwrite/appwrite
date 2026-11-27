<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Document;

final class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'This is my secret';

    private const string BODY = 'Hello, World!';

    private const string SIGNATURE = 'hmacsha256=7f062172b01cb00b53ca068614674a3d982a34062a0f5d37687d5e3377e54657';

    public function testAppleVectorIsAccepted(): void
    {
        $connection = $this->connection(self::SECRET);

        $verified = Webhooks::verify($connection, 'target', self::BODY, self::SIGNATURE);

        $this->assertTrue($verified);
    }

    /**
     * @return \Iterator<string, array{string, string, string, string}>
     */
    public static function refusals(): \Iterator
    {
        yield 'tampered body' => [self::SECRET, 'target', 'Hello, World?', self::SIGNATURE];
        yield 'wrong secret' => ['This is not my secret', 'target', self::BODY, self::SIGNATURE];
        yield 'missing prefix' => [self::SECRET, 'target', self::BODY, '7f062172b01cb00b53ca068614674a3d982a34062a0f5d37687d5e3377e54657'];
        yield 'uppercase digest' => [self::SECRET, 'target', self::BODY, 'hmacsha256=7F062172B01CB00B53CA068614674A3D982A34062A0F5D37687D5E3377E54657'];
        yield 'empty header' => [self::SECRET, 'target', self::BODY, ''];
        yield 'no stored secret' => ['', 'target', self::BODY, self::SIGNATURE];
        yield 'secret of another target' => [self::SECRET, 'other', self::BODY, self::SIGNATURE];
    }

    #[DataProvider('refusals')]
    public function testSignatureIsRefused(string $secret, string $targetId, string $body, string $signature): void
    {
        $connection = $this->connection($secret);

        $verified = Webhooks::verify($connection, $targetId, $body, $signature);

        $this->assertFalse($verified);
    }

    public function testConnectionWithoutWebhooksIsRefused(): void
    {
        $connection = new Document(['$id' => 'connection', 'credentials' => ['keyId' => 'KEY1234567']]);

        $verified = Webhooks::verify($connection, 'target', self::BODY, self::SIGNATURE);

        $this->assertFalse($verified);
    }

    public function testEventIsDuplicateOnlyOnceRemembered(): void
    {
        $cache = new Cache(new Memory());
        $project = new Document(['$id' => 'project', '$sequence' => '7']);

        $first = Webhooks::isDuplicate($cache, $project, 'connection', 'target', 'event-1');
        $checked = Webhooks::isDuplicate($cache, $project, 'connection', 'target', 'event-1');
        Webhooks::remember($cache, $project, 'connection', 'target', 'event-1');

        $this->assertFalse($first);
        $this->assertFalse($checked);
        $this->assertTrue(Webhooks::isDuplicate($cache, $project, 'connection', 'target', 'event-1'));
        $this->assertFalse(Webhooks::isDuplicate($cache, $project, 'connection', 'other', 'event-1'));
        $this->assertFalse(Webhooks::isDuplicate($cache, $project, 'other', 'target', 'event-1'));
        $this->assertFalse(Webhooks::isDuplicate($cache, $project, 'connection', 'target', 'event-2'));
        $this->assertFalse(Webhooks::isDuplicate($cache, new Document(['$id' => 'project', '$sequence' => '8']), 'connection', 'target', 'event-1'));
    }

    private function connection(string $secret): Document
    {
        return new Document([
            '$id' => 'connection',
            'provider' => 'apple',
            'credentials' => [
                'keyId' => 'KEY1234567',
                'webhooks' => [
                    'target' => ['id' => 'webhook-1', 'appId' => 'store-app', 'secret' => $secret],
                ],
            ],
        ]);
    }
}
