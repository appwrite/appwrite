<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ProjectCustomServerTest extends Scope
{
    use ProjectBase;
    use ProjectCustom;
    use SideServer;

    // Placeholder until this scope has custom server-specific coverage.
    // You can remove this after adding some custom server tests, or some project base tests
    public function testProjectServerLogic(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
