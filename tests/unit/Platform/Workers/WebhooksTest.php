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

        $this->assertSame($expected, $method->invoke(null, $membership, 'project-1'));
    }

    public static function ownerRoleProvider(): array
    {
        return [
            'array owner' => [['owner'], true],
            'array mixed case owner' => [['Owner'], true],
            'project owner' => [['project-project-1-owner'], true],
            'mixed case project owner' => [['Project-Project-1-Owner'], true],
            'comma string owner' => ['developer, owner', true],
            'comma string project owner' => ['developer, project-project-1-owner', true],
            'other project owner' => [['project-project-2-owner'], false],
            'non owner' => [['developer'], false],
            'invalid roles' => [null, false],
        ];
    }
}
