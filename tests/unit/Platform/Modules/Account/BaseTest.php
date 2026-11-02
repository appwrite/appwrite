<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Base;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class BaseTest extends TestCase
{
    public function testIdentityEmailLockKeyIgnoresCase(): void
    {
        $project = new Document(['$id' => 'project', '$sequence' => '1']);

        $this->assertSame(
            Base::identityEmailLockKey($project, 'Owner@Example.com'),
            Base::identityEmailLockKey($project, 'owner@example.com'),
        );
    }

    public function testIdentityEmailLockKeyIsScopedToProject(): void
    {
        $this->assertNotSame(
            Base::identityEmailLockKey(new Document(['$id' => 'first', '$sequence' => '1']), 'owner@example.com'),
            Base::identityEmailLockKey(new Document(['$id' => 'second', '$sequence' => '2']), 'owner@example.com'),
        );
    }

    public function testIdentityEmailLockKeySeparatesAddresses(): void
    {
        $project = new Document(['$id' => 'project', '$sequence' => '1']);

        $this->assertNotSame(
            Base::identityEmailLockKey($project, 'owner@example.com'),
            Base::identityEmailLockKey($project, 'other@example.com'),
        );
    }

    public function testIdentityEmailLockKeyHidesAddress(): void
    {
        $key = Base::identityEmailLockKey(new Document(['$id' => 'project', '$sequence' => '1']), 'owner@example.com');

        $this->assertStringNotContainsString('owner', $key);
        $this->assertStringNotContainsString('example.com', $key);
        $this->assertStringStartsWith('lock:1:identities:email:', $key);
    }
}
