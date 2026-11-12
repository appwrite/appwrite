<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use PHPUnit\Framework\TestCase;
use Utopia\Pools\Group;

require_once __DIR__ . '/../../../app/init.php';

final class LockPoolTest extends TestCase
{
    public function testLockPoolIsNotSizedOffTheDatabaseBudget(): void
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $lock = $pools->get('lock')->size;

        $this->assertGreaterThan(
            1,
            $lock,
            'A lock lease is held for as long as the work it guards, so a second concurrent taker needs its own connection to wait on the lock with. The shared size is derived from the MySQL connection budget and floored by a worker-only setting, which leaves this server with one; the taker then cannot get a connection to queue with and 500s instead.'
        );
    }
}
