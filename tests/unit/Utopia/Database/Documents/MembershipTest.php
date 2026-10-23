<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\Membership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class MembershipTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function channels(): \Iterator
    {
        yield 'stored email with both contacts' => [
            ['channel' => 'email'],
            ['email' => 'owner@localhost.test', 'phone' => '+12025550100'],
            'email',
        ];
        yield 'stored phone with both contacts' => [
            ['channel' => 'phone'],
            ['email' => 'owner@localhost.test', 'phone' => '+12025550100'],
            'phone',
        ];
        yield 'legacy email only' => [
            [],
            ['email' => 'owner@localhost.test'],
            'email',
        ];
        yield 'legacy phone only' => [
            [],
            ['phone' => '+12025550100'],
            'phone',
        ];
        yield 'legacy empty channel and both contacts' => [
            ['channel' => ''],
            ['email' => 'owner@localhost.test', 'phone' => '+12025550100'],
            '',
        ];
        yield 'legacy missing channel and both contacts' => [
            [],
            ['email' => 'owner@localhost.test', 'phone' => '+12025550100'],
            '',
        ];
        yield 'legacy neither contact' => [
            [],
            [],
            '',
        ];
        yield 'unknown stored value infers email only' => [
            ['channel' => 'sms'],
            ['email' => 'owner@localhost.test'],
            'email',
        ];
    }

    /**
     * @param array<string, mixed> $membership
     * @param array<string, mixed> $user
     */
    #[DataProvider('channels')]
    public function testDeliveredChannel(array $membership, array $user, string $expected): void
    {
        $this->assertSame(
            $expected,
            (new Membership($membership))->deliveredChannel(new Document($user))
        );
    }
}
