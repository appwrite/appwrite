<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Platform\Modules\Teams\Http\Memberships\Create;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../app/init.php';

final class CreateTest extends TestCase
{
    /**
     * SDKs map positional arguments from the declared parameter order, so a new optional
     * parameter declared before existing ones turns `createMembership(teamId, roles, email)`
     * into a call that sends the email as the membership ID.
     */
    public function testMembershipIdIsDeclaredAfterExistingParameters(): void
    {
        $action = new Create();
        $params = \array_keys($action->getParams());

        $this->assertSame(
            ['teamId', 'email', 'userId', 'phone', 'roles', 'url', 'name', 'membershipId'],
            $params,
        );

        // Route values reach the callback positionally, so its arguments must follow the same order.
        $arguments = \array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionFunction($action->getCallback()))->getParameters(),
        );
        $this->assertSame($params, \array_slice($arguments, 0, \count($params)));
    }
}
