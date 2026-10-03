<?php

declare(strict_types=1);

namespace Tests\Unit\Presences;

use Appwrite\Presences\State;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

final class StateTest extends TestCase
{
    public function testSetPermissionsDefaultsForUser(): void
    {
        $userId = 'user123';
        $user = new User(['$id' => $userId]);
        $authorization = new Authorization();
        $authorization->addRole(Role::user($userId)->toString());

        $document = (new State())->setPermissions(
            new Document(),
            null,
            $user,
            $authorization,
        );

        $this->assertSame($this->expectedPermissions($userId), $document->getAttribute('$permissions'));
        $this->assertSame(\md5(\json_encode($this->expectedPermissions($userId))), $document->getAttribute('permissionsHash'));
    }

    public function testSetPermissionsOwnerOverride(): void
    {
        $ownerId = 'owner456';
        $document = (new State())->setPermissions(
            new Document(),
            null,
            new User(),
            new Authorization(),
            ownerOverride: $ownerId,
        );

        $this->assertSame($this->expectedPermissions($ownerId), $document->getAttribute('$permissions'));
        $this->assertSame(\md5(\json_encode($this->expectedPermissions($ownerId))), $document->getAttribute('permissionsHash'));
    }

    /**
     * @return array<int, string>
     */
    private function expectedPermissions(string $userId): array
    {
        $permissions = [
            (new Permission('read', 'user', $userId))->toString(),
            (new Permission('update', 'user', $userId))->toString(),
            (new Permission('delete', 'user', $userId))->toString(),
            (new Permission('write', 'user', $userId))->toString(),
        ];
        sort($permissions, SORT_STRING);

        return $permissions;
    }
}
