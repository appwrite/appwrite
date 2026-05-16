<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../../app/init.php';

class WebhooksTest extends TestCase
{
    #[DataProvider('ownerRoleProvider')]
    public function testOwnerRoleDetectionAcceptsArrayAndLegacyStringRoles(mixed $roles, bool $expected): void
    {
        $method = new \ReflectionMethod(Webhooks::class, 'hasOwnerRole');
        $membership = new Document([
            '$id' => 'membership-1',
            'roles' => $roles,
        ]);

        $this->assertSame($expected, $method->invoke(null, $membership));
    }

    public static function ownerRoleProvider(): array
    {
        return [
            'array owner' => [['owner'], true],
            'array mixed case owner' => [['Owner'], true],
            'comma string owner' => ['developer, owner', true],
            'non owner' => [['developer'], false],
            'invalid roles' => [null, false],
        ];
    }
}
